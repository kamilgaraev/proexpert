<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use InvalidArgumentException;

final readonly class ProjectPlanningCoordinator
{
    public function __construct(
        private ProjectModelRepository $models,
        private TechnologyRecommendationService $recommendations,
        private TechnologySystemCatalog $catalog,
        private int $maxFacts,
        private int $maxRecommendations,
    ) {
        if ($maxFacts < 1 || $maxFacts > 10000 || $maxRecommendations < 1 || $maxRecommendations > 50) {
            throw new InvalidArgumentException('Project planning limits are invalid.');
        }
    }

    public function refresh(
        int $organizationId,
        int $projectId,
        int $sessionId,
        OrganizationPreferenceContext $preferences,
        ProjectUnderstandingResult $understanding,
    ): ProjectPlanningResult {
        if ($preferences->organizationId !== $organizationId) {
            throw new InvalidArgumentException('Project planning preferences are outside the requested tenant.');
        }
        if (! $understanding->isReadyForPlanning()) {
            return $this->limited(
                $understanding->inputFingerprint ?? '',
                $understanding->limitations === [] ? ['planning_blocked_by_understanding'] : $understanding->limitations,
                $understanding->status,
                $understanding->sourceVersion,
            );
        }
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts + 1);
        $snapshot = $capture['snapshot'];
        $inputFingerprint = $capture['token'];
        $currentUnderstanding = $this->models->currentUnderstanding($organizationId, $projectId, $sessionId);
        if ($currentUnderstanding === null
            || ! hash_equals($understanding->inputFingerprint, (string) ($currentUnderstanding['input_fingerprint'] ?? ''))
            || ! hash_equals($understanding->sourceVersion, (string) ($currentUnderstanding['source_version'] ?? ''))) {
            return $this->limited($inputFingerprint, 'planning_understanding_not_current', 'unresolved');
        }
        if (count($snapshot->facts) > $this->maxFacts) {
            return $this->limited($inputFingerprint, 'planning_fact_budget_exceeded', 'unresolved');
        }
        $sourceVersions = array_values(array_unique(array_map(
            static fn (Fact $fact): string => $fact->sourceVersion,
            $snapshot->facts,
        )));
        sort($sourceVersions, SORT_STRING);
        $sourceVersion = count($sourceVersions) === 1
            ? $sourceVersions[0]
            : 'sha256:'.hash('sha256', implode("\0", $sourceVersions));
        if (! hash_equals($understanding->sourceVersion, $sourceVersion)) {
            return $this->limited($inputFingerprint, 'planning_understanding_not_current', 'unresolved');
        }
        $replayed = $this->models->replayTechnologyRecommendations(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $this->catalog->version,
            $this->catalog->contentHash,
        );
        if ($replayed !== null) {
            return new ProjectPlanningResult(
                $sourceVersion,
                $inputFingerprint,
                $this->catalog->version,
                $this->catalog->contentHash,
                $replayed['recommendations'],
                $replayed['limitations'],
            );
        }
        $roofEntities = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->status === 'confirmed' && $fact->type === 'roof_type') {
                $roofEntities[$fact->entityId] = true;
            }
        }
        $targets = [];
        $priority = ['material' => 1, 'material_name' => 2, 'roof_covering_system' => 3];
        foreach ($snapshot->facts as $fact) {
            if ($fact->origin !== 'unresolved' || $fact->status !== 'unresolved'
                || ! isset($priority[$fact->type])
                || ($fact->type !== 'roof_covering_system' && ! isset($roofEntities[$fact->entityId]))) {
                continue;
            }
            $existing = $targets[$fact->entityId] ?? null;
            if (! $existing instanceof Fact || $priority[$fact->type] > $priority[$existing->type]) {
                $targets[$fact->entityId] = $fact;
            }
        }
        ksort($targets, SORT_STRING);
        $recommendations = [];
        foreach ($targets as $fact) {
            $recommendations[] = $this->recommendations->recommend($snapshot, $fact, $preferences);
            if (count($recommendations) >= $this->maxRecommendations) {
                break;
            }
        }
        $limitations = count($recommendations) >= $this->maxRecommendations
            ? ['planning_recommendation_budget_reached']
            : [];
        $saved = $this->models->replaceTechnologyRecommendations(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $inputFingerprint,
            $this->catalog->version,
            $this->catalog->contentHash,
            $recommendations,
            $limitations,
        );
        if (! $saved) {
            return $this->limited($inputFingerprint, 'planning_stale_snapshot', 'stale');
        }

        return new ProjectPlanningResult(
            $sourceVersion,
            $inputFingerprint,
            $this->catalog->version,
            $this->catalog->contentHash,
            $recommendations,
            $limitations,
        );
    }

    private function limited(
        string $inputFingerprint,
        string|array $limitation,
        string $status,
        ?string $sourceVersion = null,
    ): ProjectPlanningResult {
        return new ProjectPlanningResult(
            $sourceVersion ?? 'sha256:'.str_repeat('0', 64),
            $inputFingerprint,
            $this->catalog->version,
            $this->catalog->contentHash,
            [],
            is_array($limitation) ? array_values(array_unique($limitation)) : [$limitation],
            $status,
        );
    }
}
