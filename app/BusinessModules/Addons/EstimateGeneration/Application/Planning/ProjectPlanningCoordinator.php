<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

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
    ): ProjectPlanningResult {
        if ($preferences->organizationId !== $organizationId) {
            throw new InvalidArgumentException('Project planning preferences are outside the requested tenant.');
        }
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts + 1);
        $snapshot = $capture['snapshot'];
        $inputFingerprint = $capture['token'];
        if (count($snapshot->facts) > $this->maxFacts) {
            return $this->limited($inputFingerprint, 'planning_fact_budget_exceeded');
        }
        $sourceVersions = array_values(array_unique(array_map(
            static fn (Fact $fact): string => $fact->sourceVersion,
            $snapshot->facts,
        )));
        if (count($sourceVersions) !== 1) {
            throw new InvalidArgumentException('Project planning snapshot has no exact source version.');
        }
        $sourceVersion = $sourceVersions[0];
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
        $recommendations = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->origin !== 'unresolved' || $fact->status !== 'unresolved'
                || ! in_array($fact->type, ['material', 'material_name', 'roof_covering_system'], true)) {
                continue;
            }
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
            return $this->limited($inputFingerprint, 'planning_stale_snapshot');
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

    private function limited(string $inputFingerprint, string $limitation): ProjectPlanningResult
    {
        return new ProjectPlanningResult(
            'sha256:'.str_repeat('0', 64),
            $inputFingerprint,
            $this->catalog->version,
            $this->catalog->contentHash,
            [],
            [$limitation],
        );
    }
}
