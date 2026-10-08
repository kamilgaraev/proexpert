<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudget;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AssistantStatusSnapshotBuilder
{
    public function __construct(
        private readonly AssistantStatusSnapshotEpoch $epoch,
        private readonly AssistantStatusSnapshotInputs $inputs,
        private readonly AssistantDataAccessPolicy $access,
        private readonly RagCoverageService $coverage,
        private readonly AssistantDocumentCoverageService $documents,
        private readonly AssistantStatusSnapshotFootprint $footprint,
    ) {}

    public function refresh(int $organizationId, int $actorId, KnowledgeSurface $surface, string $cacheKey, string $section, ?string $ip): void
    {
        if ($this->access->trustedSurface() !== $surface || ! in_array($section, ['sources', 'documents', 'all'], true)) {
            throw new \LogicException('Assistant snapshot scope mismatch');
        }
        if (Cache::add('ai-rag-status-epoch:purge', true, 60)) { $this->epoch->purge(600); }
        $snapshot = DB::connection()->transaction(function () use ($organizationId, $actorId, $surface, $section, $ip): ?array {
            DB::connection()->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $capturedAt = DB::selectOne('SELECT LEAST(transaction_timestamp(), clock_timestamp())::text AS captured_at')->captured_at;
            $budget = new RagStatusBudget(DB::connection(), 60000);
            $observed = $this->footprint->capture(fn (): array => $budget->run(function (callable $checkpoint) use ($organizationId, $actorId, $surface, $section, $ip, $budget, $capturedAt): array {
                $actor = User::query()->find($actorId);
                if ($actor === null || ! $actor->is_active || (int) $actor->current_organization_id !== $organizationId) { return []; }

                return $this->access->withCurrentChecks($actor, $organizationId,
                    function (AuthorizationService $authorization) use ($organizationId, $actor, $surface, $section, $ip, $checkpoint, $budget, $capturedAt): array {
                        $scope = $authorization->captureCurrentDecisions(['ip' => $ip], function () use ($organizationId, $actor, $surface, $section, $ip, $checkpoint, $budget, $capturedAt, $authorization): array {
                            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')) { return []; }
                            $fingerprint = $this->inputs->fingerprint($actor, $organizationId, $surface, $section, $ip, $authorization);
                            if ($fingerprint === null) { return []; }
                            $generation = $this->generation($organizationId);
                            $captured = $authorization->captureCurrentDecisions(['ip' => $ip], function () use ($organizationId, $actor, $section, $checkpoint, $budget, $authorization): array {
                                $this->access->prefetchEntitySchemaMetadata($checkpoint, $budget->checkDeadline(...));
                                $proof = null;
                                $sources = $section === 'documents' ? [] : $this->coverage->coverageForActor($organizationId, $actor,
                                    $checkpoint, $budget->checkDeadline(...), $proof, countsOnly: true);
                                $documents = $section === 'sources' ? [] : $this->documents->coverage($organizationId, $actor,
                                    $checkpoint, $budget->checkDeadline(...));

                                return array_merge($sources, $documents, ['status_available' => true,
                                    'can_reindex' => $authorization->canCurrent($actor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]),
                                    'stale_after_seconds' => 60]);
                            });
                            foreach ($captured['decisions'] as $decision) {
                                if (! $this->inputs->serializableContext($decision['context'])) { return []; }
                            }
                            if (! $this->access->withCurrentChecks($actor, $organizationId,
                                static fn (AuthorizationService $current): bool => $current === $authorization)) { return []; }
                            $validUntil = $this->inputs->validUntil($capturedAt, 60, $organizationId, (int) $actor->id);
                            if (! CarbonImmutable::parse($validUntil)->isFuture() || $generation !== $this->generation($organizationId)) { return []; }

                            return ['status' => $captured['value'], 'decisions' => $captured['decisions'],
                                'fingerprint' => $fingerprint, 'valid_until' => $validUntil, 'projection_generation' => $generation,
                                'generated_at' => now()->toIso8601String()];
                        });
                        if ($scope['value'] === []) { return []; }

                        return array_replace($scope['value'], ['decisions' => array_merge($scope['value']['decisions'], $scope['decisions'])]);
                    }, fresh: true, checkpoint: $budget->checkDeadline(...));
            }));
            if ($observed['value'] === [] || $observed['relations'] === null) {
                \Illuminate\Support\Facades\Log::warning('assistant.rag_status_snapshot_unavailable', ['organization_id' => $organizationId,
                    'reason' => $observed['reason'] ?? ($observed['value'] === [] ? 'access_inputs_changed' : 'unproven_dependencies')]);

                return null;
            }
            $epoch = $this->epoch->capture($observed['relations'], $organizationId);
            if (! $epoch['cacheable']) {
                \Illuminate\Support\Facades\Log::warning('assistant.rag_status_snapshot_unavailable', ['organization_id' => $organizationId, 'reason' => 'unproven_database_epoch']);

                return null;
            }

            return array_merge($observed['value'], ['epoch' => $epoch]);
        }, 1);
        $phase = $snapshot === null ? 'snapshot_unproven' : 'snapshot_expired_or_changed';
        $expectedKey = null;
        if ($snapshot !== null && $this->inputs->isUnexpired($snapshot['valid_until'])
            && $snapshot['projection_generation'] === $this->generation($organizationId)) {
            $expectedKey = 'ai-rag-status:'.$organizationId.':'.$actorId.':'.$surface->value.':'.$section.':'.$snapshot['fingerprint'];
            $phase = 'cache_key_mismatch';
            if ($expectedKey === $cacheKey) {
                Cache::put($cacheKey, $snapshot, 60);
                $phase = 'written';
            }
        }
        AssistantStatusSnapshotDiagnostics::refresh($phase, $section, $cacheKey, $expectedKey);
    }

    private function generation(int $organizationId): ?string
    {
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organizationId, 0);
        $snapshot = Cache::get('ai-rag-coverage:'.$organizationId.':0:*:'.$revision);

        return is_array($snapshot) && is_string($snapshot['projection_generation'] ?? null) ? $snapshot['projection_generation'] : null;
    }
}
