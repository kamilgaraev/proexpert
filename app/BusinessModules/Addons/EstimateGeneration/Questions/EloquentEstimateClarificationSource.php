<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class EloquentEstimateClarificationSource implements EstimateClarificationCatalog, EstimateClarificationSource
{
    public function __construct(
        private ProjectModelRepository $models,
        private ResolveCurrentEstimateClarification $resolver,
        private int $maxFacts,
    ) {}

    public function findCurrent(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $questionKey,
    ): ?CurrentEstimateClarification {
        foreach ($this->allCurrent($organizationId, $projectId, $sessionId) as $current) {
            if ($current->question->code === $questionKey) {
                return $current;
            }
        }

        return null;
    }

    public function allCurrent(int $organizationId, int $projectId, int $sessionId): array
    {
        $understanding = $this->models->currentUnderstanding($organizationId, $projectId, $sessionId);
        $capture = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts,
        );

        $questions = $understanding === null ? [] : $this->resolver->resolveAll(
            is_array($understanding['questions'] ?? null) ? array_values($understanding['questions']) : [],
            (string) ($understanding['source_version'] ?? ''),
            $capture['snapshot'],
            $capture['token'],
        );
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->whereKey($sessionId)->first();
        if ($session !== null && $session->status === EstimateGenerationStatus::InputReviewRequired) {
            $composer = $this->resolver->resolveComposer($session->draft_payload ?? [], $session->input_payload ?? [],
                $capture['snapshot'], $capture['token'], [$organizationId, $projectId, $sessionId], (int) $session->state_version);
            if ($composer !== null) {
                $questions[] = $composer;
            }
        }
        if ($session !== null && ($session->status === EstimateGenerationStatus::EstimateReviewRequired
            || (is_array($session->draft_payload) && str_contains(json_encode($session->draft_payload, JSON_THROW_ON_ERROR), 'work_technology_context')))) {
            $entries = $this->currentWorkContexts($organizationId, $projectId, $sessionId, $capture['snapshot']);
            $questions = [...$questions, ...$this->resolver->workTechnologyQuestions($entries, $capture['token'], (int) $session->state_version)];
        }

        return $questions;
    }

    public function currentWorkContexts(int $organizationId, int $projectId, int $sessionId,
        ?\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot $snapshot = null, ?array $decisions = null): array
    {
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->whereKey($sessionId)->first();
        if ($session === null || ! is_array($session->draft_payload)
            || ! str_contains(json_encode($session->draft_payload, JSON_THROW_ON_ERROR), 'work_technology_context')) {
            return [];
        }
        $snapshot ??= $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts)['snapshot'];
        $decisions ??= $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, array_column($snapshot->facts, 'id'));

        return $this->resolver->workTechnologyContexts($session->draft_payload, $session->input_payload ?? [], $snapshot, $decisions,
            [$organizationId, $projectId, $sessionId], self::currentDocuments($organizationId, $projectId, $sessionId));
    }

    public static function currentDocuments(int $organizationId, int $projectId, int $sessionId, bool $lock = false, ?array $ids = null): array
    {
        $query = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->whereNull('ignored_at')->whereIn('status', ['ready', 'needs_review'])->orderBy('id');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        if ($lock) {
            $query->sharedLock();
        }
        $result = [];
        foreach ($query->get(['id', 'status', 'source_version', 'checksum_sha256']) as $document) {
            $checksum = strtolower(trim((string) $document->checksum_sha256));
            if (preg_match('/\A[a-f0-9]{64}\z/D', $checksum) !== 1
                || ! hash_equals('sha256:'.$checksum, (string) $document->source_version)) {
                continue;
            }
            $result[] = ['document_id' => (int) $document->id, 'source_version' => $document->source_version,
                'checksum_sha256' => $checksum, 'status' => $document->status];
        }

        return $result;
    }
}
