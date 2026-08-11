<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use InvalidArgumentException;

final readonly class ProjectCompletenessCoordinator
{
    public function __construct(
        private ProjectModelRepository $models,
        private ProjectCompletenessAnalyzer $analyzer,
        private CompletenessRuleCatalog $rules,
        private int $maxFacts,
    ) {
        if ($maxFacts < 1 || $maxFacts > 10000) {
            throw new InvalidArgumentException('Completeness fact limit is invalid.');
        }
    }

    public function refresh(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ProjectPlanningResult $planning,
    ): ProjectCompletenessProjectionResult {
        if (! $planning->isReadyForCompleteness()) {
            throw new InvalidArgumentException('Completeness requires a current technology planning result.');
        }
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts + 1);
        if (count($capture['snapshot']->facts) > $this->maxFacts
            || ! hash_equals($planning->inputFingerprint, $capture['token'])) {
            throw new InvalidArgumentException('Completeness snapshot does not match technology planning.');
        }
        $sourceVersions = array_values(array_unique(array_map(
            static fn (Fact $fact): string => $fact->sourceVersion,
            $capture['snapshot']->facts,
        )));
        if ($sourceVersions !== [$planning->sourceVersion]) {
            throw new InvalidArgumentException('Completeness snapshot is outside the requested scope.');
        }
        $replayed = $this->models->replayCompleteness(
            $organizationId, $projectId, $sessionId, $planning->sourceVersion, $planning->inputFingerprint,
            $planning->catalogVersion, $planning->catalogHash, $this->rules->version, $this->rules->contentHash,
        );
        if ($replayed !== null) {
            return $this->result($planning, $replayed['findings'], $replayed['limitations']);
        }
        $decisionIds = [];
        foreach ($capture['snapshot']->facts as $fact) {
            if (($fact->type === 'completeness_exclusion' || str_starts_with($fact->type, 'completeness_exclusion.'))
                && is_array($fact->value)
                && is_string($fact->value['decision_id'] ?? null)) {
                $decisionIds[] = $fact->value['decision_id'];
            }
        }
        $decisions = $this->models->decisions(
            $organizationId,
            $projectId,
            $sessionId,
            array_slice(array_values(array_unique($decisionIds)), 0, 100),
        );
        $analysis = $this->analyzer->analyze($capture['snapshot'], $planning->recommendations, $decisions, [
            'source_version' => $planning->sourceVersion,
            'input_fingerprint' => $planning->inputFingerprint,
            'catalog_version' => $planning->catalogVersion,
            'catalog_hash' => $planning->catalogHash,
            'rule_catalog_version' => $this->rules->version,
            'rule_catalog_hash' => $this->rules->contentHash,
        ]);
        $saved = $this->models->replaceCompleteness(
            $organizationId, $projectId, $sessionId, $planning->sourceVersion, $planning->inputFingerprint,
            $planning->catalogVersion, $planning->catalogHash, $this->rules->version, $this->rules->contentHash,
            $analysis->findings, $analysis->limitations,
        );
        if (! $saved) {
            throw new InvalidArgumentException('Completeness snapshot changed before persistence.');
        }

        return $this->result($planning, $analysis->findings, $analysis->limitations);
    }

    private function result(ProjectPlanningResult $planning, array $findings, array $limitations): ProjectCompletenessProjectionResult
    {
        return new ProjectCompletenessProjectionResult(
            $planning->sourceVersion, $planning->inputFingerprint, $planning->catalogVersion, $planning->catalogHash,
            $this->rules->version, $this->rules->contentHash, $findings, $limitations,
        );
    }
}
