<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\Models\User;
use Illuminate\Support\Str;

final class RebuildGeneratedSection
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private AdvanceEstimateGeneration $advance,
        private EstimateGenerationActionAuthorizer $authorizer,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        int $expectedVersion,
        string $sectionKey,
        User $actor,
        ?array $approval = null,
    ): EstimateGenerationSession {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($session, $expectedVersion, $sectionKey, $actor, $approval): EstimateGenerationSession {
            $session = EstimateGenerationSession::query()->whereKey($session->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $session->project_id)->lockForUpdate()->firstOrFail();
            $this->authorizer->authorize($actor, $session, 'estimate_generation.generate');
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProductAccess::class)->requireAvailable((int) $session->organization_id);
            $shared = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $session->organization_id);
            $attemptId = (string) Str::uuid();
            if ($shared) {
                if (! is_array($approval) || ! Str::isUuid($approval['request_id'] ?? '') || ! Str::isUuid($approval['quote_id'] ?? '')) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Подтвердите стоимость пересборки перед запуском.']);
                }
                $attemptId = strtolower($approval['request_id']);
                $prior = \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $attemptId)
                    ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                    ->where('session_id', $session->id)->where('actor_user_id', $actor->id)->where('kind', 'rebuild')->lockForUpdate()->first();
                if ($prior !== null && $prior->receipt !== null) {
                    $quote = \App\Models\Credits\AICreditQuote::query()->whereKey($prior->quote_id)->firstOrFail();
                    $plan = json_decode($prior->processing_plan, true, 64, JSON_THROW_ON_ERROR);
                    if ($quote->public_id !== strtolower($approval['quote_id']) || (int) $prior->approved_state_version !== $expectedVersion
                        || ($plan['operation_parameters']['local_estimate_key'] ?? null) !== $sectionKey) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['request_id' => 'Идентификатор относится к другой пересборке.']);
                    }

                    return $session;
                }
            }
            $this->policy->review($session, $expectedVersion);
            $targets = array_filter($session->draft_payload['local_estimates'] ?? [], static fn (array $estimate): bool => ($estimate['key'] ?? null) === $sectionKey);
            if (count($targets) !== 1) {
                throw \Illuminate\Validation\ValidationException::withMessages(['local_estimate_key' => 'Раздел отсутствует в текущем составе сметы.']);
            }
            $payload = [...($session->input_payload ?? []), 'rebuild_section_key' => $sectionKey];
            if ($shared) {
                try {
                    app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                        ->begin($actor, $session, $expectedVersion, $attemptId, $approval['quote_id'], 'rebuild', ['local_estimate_key' => $sectionKey]);
                } catch (\DomainException) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['quote_id' => 'Предложение стоимости недействительно либо баланс недостаточен. Получите новое предложение.']);
                }
                $payload['generation_credit_operation_id'] = $attemptId;
            } else {
                unset($payload['generation_credit_operation_id']);
            }
            $session = $this->advance->update($session, [$session->status], ['input_payload' => $payload]);
            $session = $this->advance->generationStarted($session, $attemptId);
            if ($shared) {
                \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $attemptId)->update([
                    'receipt' => \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode([
                        'operation_id' => $attemptId, 'status' => 'queued', 'state_version' => (int) $session->state_version]), 'updated_at' => now()]);
            }
            GenerateEstimateDraftJob::dispatch((int) $session->id, (int) $session->state_version, $attemptId,
                FailureExecutionSnapshot::capture($session, 'rebuild_generated_section', $attemptId))
                ->onQueue(GenerateEstimateDraftJob::QUEUE)->afterCommit();

            return $session;
        }, 3);
    }
}
