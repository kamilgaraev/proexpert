<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Credits\AICreditQuote;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Support\AI\LunaModelPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class OrdinaryAssistantEnrollmentService
{
    public const PLATFORM_PERMISSION = 'admin.ai_assistant.egress.manage';

    public const DECLARATION = 'owner_declared_synthetic_input_and_sources/1';

    public function __construct(private AuthorizationService $authorization, private AssistantDataAccessPolicy $access,
        private OrdinaryAssistantSourceCatalog $sources, private AICreditService $credits) {}

    public function assign(User $operator, int $organizationId, User $actor, int $projectId, array $tools, string $policyVersion = OrdinaryAssistantSourceProjection::VERSION): AssistantEgressCohort
    {
        $this->assertPlatform($operator);
        if (! $actor->is_active || (int) $actor->current_organization_id !== $organizationId
            || ! User::query()->whereKey($actor->getKey())->where('is_active', true)->where('current_organization_id', $organizationId)->exists()
            || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)
            || ! OrdinaryAssistantSourceProjection::allowsTools($policyVersion, $tools)) {
            throw new AssistantEgressDenied('access_denied');
        }
        $provider = (string) config('ai-assistant.llm.provider', 'timeweb');
        $model = LunaModelPolicy::forProvider($provider);
        $endpoint = rtrim((string) (config('ai-assistant.llm.'.$provider.'.base_uri')
            ?: ($provider === 'timeweb' ? 'https://api.timeweb.ai/v1' : 'https://api.openai.com/v1')), '/').'/responses';
        $uri = parse_url($endpoint);
        if (! is_array($uri) || ($uri['scheme'] ?? null) !== 'https' || empty($uri['host'])
            || isset($uri['user']) || isset($uri['pass']) || isset($uri['query']) || isset($uri['fragment'])) {
            throw new AssistantEgressDenied('unsupported_purpose');
        }

        return DB::transaction(function () use ($operator, $organizationId, $actor, $projectId, $tools, $provider, $model, $endpoint, $policyVersion): AssistantEgressCohort {
            $existing = AssistantEgressCohort::query()->where('organization_id', $organizationId)->where('actor_id', $actor->id)->lockForUpdate()->first();
            $cohort = $existing ?? new AssistantEgressCohort(['id' => (string) Str::uuid()]);
            $cohort->fill(['generation' => (string) Str::uuid(), 'organization_id' => $organizationId, 'actor_id' => $actor->id, 'project_id' => $projectId,
                'assigned_by' => $operator->id, 'status' => 'active', 'policy_version' => $policyVersion,
                'provider' => $provider, 'model' => $model, 'endpoint' => $endpoint, 'allowed_tools' => array_values(array_unique($tools)), 'expires_at' => now()->addDay()]);
            $cohort->save();
            if ($existing !== null) {
                AssistantEgressEnrollment::query()->where('cohort_id', $cohort->id)->update(['status' => 'revoked', 'updated_at' => now()]);
            }

            return $cohort;
        });
    }

    public function preview(User $owner, int $organizationId, string $cohortId, string $quoteId, array $input, array $requestedSources): array
    {
        $this->assertOwner($owner, $organizationId);
        $cohort = $this->cohort($cohortId, $organizationId);
        $actor = User::query()->findOrFail($cohort->actor_id);
        $hash = $this->credits->canonicalAssistantRequest($input);
        $quote = AICreditQuote::query()->where('public_id', $quoteId)->where('organization_id', $organizationId)
            ->where('user_id', $cohort->actor_id)->where('request_hash', $hash)->where('expires_at', '>', now())->first();
        if ($quote === null || ! is_string($input['message'] ?? null) || trim($input['message']) === ''
            || ! empty($input['attachment_ids']) || ! empty($input['attachment_manifest']) || ($input['allow_actions'] ?? false)
            || $requestedSources === [] || count($requestedSources) > 6
            || ! OrdinaryAssistantSourceProjection::allowsTools($cohort->policy_version, array_column($requestedSources, 'tool'))
            || ($cohort->policy_version === OrdinaryAssistantDocumentProjection::VERSION && count($requestedSources) !== 1)) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        $inputContext = app(OrdinaryAssistantClarificationContext::class)->preview($input, $actor, $cohort, (int) $owner->id);
        $proofs = $previews = [];
        foreach ($requestedSources as $source) {
            if (! is_array($source) || ! in_array($source['tool'] ?? null, $cohort->allowed_tools, true)
                || ! is_array($source['arguments'] ?? null)) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            $capture = $this->sources->captureForReview($actor, (int) $owner->id, $organizationId, (int) $cohort->project_id, $source['tool'], $source['arguments']);
            $proofs[] = $capture['proof'];
            $previews[] = ['tool' => $capture['proof']['tool'], 'arguments' => $capture['proof']['arguments'],
                'projection_hash' => $capture['proof']['projection_hash'], 'projection' => $capture['preview']];
        }
        if (! OrdinaryAssistantSourceProjection::allowsSources($cohort->policy_version, $proofs)) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        $manifestHash = OrdinaryAssistantClarificationContext::manifestHash(['cohort' => $cohort->getKey(), 'cohort_generation' => $cohort->generation,
            'input_hash' => $hash, 'sources' => $proofs], $inputContext['proof'] ?? null);
        $enrollment = AssistantEgressEnrollment::query()->create(['id' => (string) Str::uuid(), 'cohort_id' => $cohort->id, 'cohort_generation' => $cohort->generation,
            'reviewed_by' => $owner->id, 'input_hash' => $hash, 'manifest_hash' => $manifestHash, 'input_context' => $inputContext['proof'] ?? null,
            'sources' => $proofs, 'status' => 'pending_review', 'expires_at' => now()->addMinutes(15)]);

        return ['enrollment_id' => $enrollment->id, 'manifest_hash' => $manifestHash, 'input_hash' => $hash,
            'project_id' => $cohort->project_id, 'cohort_generation' => $cohort->generation, 'purpose' => OrdinaryAssistantEgressRequest::PURPOSE,
            'provider' => $cohort->provider, 'model' => $cohort->model, 'endpoint' => $cohort->endpoint, 'policy_version' => $cohort->policy_version,
            'declaration_version' => self::DECLARATION,
            'sources' => $previews, 'expires_at' => $enrollment->expires_at->toISOString()]
            + ($inputContext === null ? [] : ['input_context' => $inputContext['preview'] + ['current_reply' => $input['message']]]);
    }

    public function confirm(User $owner, int $organizationId, string $enrollmentId, string $manifestHash, string $declaration): AssistantEgressEnrollment
    {
        $this->assertOwner($owner, $organizationId);
        $enrollment = AssistantEgressEnrollment::query()->findOrFail($enrollmentId);
        $cohort = $this->cohort((string) $enrollment->cohort_id, $organizationId);
        if ($declaration !== self::DECLARATION || (int) $enrollment->reviewed_by !== (int) $owner->id || $enrollment->status !== 'pending_review'
            || ! hash_equals((string) $cohort->generation, (string) $enrollment->cohort_generation)
            || array_diff(array_column($enrollment->sources, 'tool'), $cohort->allowed_tools) !== []
            || ! OrdinaryAssistantSourceProjection::allowsSources($cohort->policy_version, $enrollment->sources)
            || $enrollment->expires_at->isPast() || ! hash_equals((string) $enrollment->manifest_hash, $manifestHash)) {
            throw new AssistantEgressDenied('access_denied');
        }
        $actor = User::query()->findOrFail($cohort->actor_id);
        if ($enrollment->input_context !== null) { app(OrdinaryAssistantClarificationContext::class)->refresh($enrollment->input_context, $actor, $cohort, $enrollment->reviewed_by); }
        foreach ($enrollment->sources as $source) {
            $current = $this->sources->captureForReview($actor, $enrollment->reviewed_by, $organizationId, (int) $cohort->project_id, $source['tool'], $source['arguments']);
            if (! hash_equals(OrdinaryAssistantSourceProjection::hash($current['proof']), OrdinaryAssistantSourceProjection::hash($source))) {
                throw new AssistantEgressDenied('source_stale');
            }
        }
        $currentManifest = OrdinaryAssistantClarificationContext::manifestHash(['cohort' => $cohort->getKey(), 'cohort_generation' => $cohort->generation,
            'input_hash' => $enrollment->input_hash, 'sources' => $enrollment->sources], $enrollment->input_context);
        if (! hash_equals($currentManifest, $manifestHash)) {
            throw new AssistantEgressDenied('source_stale');
        }
        $updated = AssistantEgressEnrollment::query()->whereKey($enrollmentId)->where('status', 'pending_review')
            ->where('cohort_generation', $cohort->generation)
            ->where('reviewed_by', $owner->id)->where('manifest_hash', $manifestHash)->where('input_hash', $enrollment->input_hash)
            ->where('expires_at', '>', now())->update(['status' => 'approved', 'declaration_version' => self::DECLARATION,
                'approved_at' => now(), 'expires_at' => min(now()->addHour(), $cohort->expires_at), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new AssistantEgressDenied('access_denied');
        }

        return $enrollment->refresh();
    }

    public function revoke(User $owner, int $organizationId, string $id): void
    {
        $this->assertOwner($owner, $organizationId);
        AssistantEgressEnrollment::query()->whereKey($id)->whereIn('cohort_id',
            AssistantEgressCohort::query()->where('organization_id', $organizationId)->select('id'))->firstOrFail()->update(['status' => 'revoked']);
    }

    public function revokeCohort(User $operator, string $id): void
    {
        $this->assertPlatform($operator);
        DB::transaction(function () use ($id): void {
            AssistantEgressCohort::query()->whereKey($id)->lockForUpdate()->firstOrFail()->update(['status' => 'revoked']);
            AssistantEgressEnrollment::query()->where('cohort_id', $id)->update(['status' => 'revoked', 'updated_at' => now()]);
        });
    }

    public function cohort(string $id, int $organizationId): AssistantEgressCohort
    {
        $cohort = AssistantEgressCohort::query()->whereKey($id)->where('organization_id', $organizationId)->first();
        if ($cohort === null || ! Str::isUuid($cohort->generation) || (int) $cohort->project_id < 1 || $cohort->status !== 'active' || $cohort->expires_at->isPast()
            || ! OrdinaryAssistantSourceProjection::allowsTools($cohort->policy_version, $cohort->allowed_tools)) {
            throw new AssistantEgressDenied('access_denied');
        }

        return $cohort;
    }

    public function assertOwner(User $owner, int $organizationId): void
    {
        if (! $owner->is_active || (int) $owner->current_organization_id !== $organizationId
            || ! User::query()->whereKey($owner->getKey())->where('is_active', true)->where('current_organization_id', $organizationId)->exists()
            || ! $this->authorization->canCurrent($owner, 'ai_assistant.settings.manage', ['organization_id' => $organizationId])
            || ! $owner->organizations()->where('organizations.id', $organizationId)
                ->wherePivot('is_active', true)->wherePivot('is_owner', true)->exists()) {
            throw new AssistantEgressDenied('access_denied');
        }
    }

    public function assertPlatform(User $operator): void
    {
        if (! $operator->is_active || ! User::query()->whereKey($operator->getKey())->where('is_active', true)->exists()
            || ! $this->authorization->canCurrent($operator, self::PLATFORM_PERMISSION, ['context_type' => 'system'])) {
            throw new AssistantEgressDenied('access_denied');
        }
    }
}
