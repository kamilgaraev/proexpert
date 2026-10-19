<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;

final readonly class EstimatePrerequisiteBacklog
{
    public function __construct(
        private ProjectModelRepository $models,
        private EstimatePrerequisiteBacklogProjector $projector,
        private TechnologySystemCatalog $catalog,
        private CompletenessRuleCatalog $rules,
    ) {}

    public static function editable(EstimateGenerationSession $session): bool
    {
        return $session->applied_estimate_id === null && ! $session->status->isTerminal()
            && ! in_array($session->status, [EstimateGenerationStatus::ProcessingDocuments,
                EstimateGenerationStatus::Generating, EstimateGenerationStatus::Applying], true);
    }

    public function current(EstimateGenerationSession $session): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $projection = $this->models->currentCompleteness(...$scope);
        $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
        $snapshot = $capture['snapshot'];
        $current = $projection !== null && count($snapshot->facts) <= 10000
            && ($projection['input_fingerprint'] ?? null) === $capture['token']
            && ($projection['catalog_version'] ?? null) === $this->catalog->version
            && ($projection['catalog_hash'] ?? null) === $this->catalog->contentHash
            && ($projection['rule_catalog_version'] ?? null) === $this->rules->version
            && ($projection['rule_catalog_hash'] ?? null) === $this->rules->contentHash;
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = [];
        foreach ($snapshot->facts as $fact) {
            $facts[$fact->entityId][$fact->type][] = $fact;
        }
        $groups = [];
        foreach (array_slice($projection['findings'] ?? [], 0, 100) as $finding) {
            if (! $finding instanceof CompletenessFinding) {
                continue;
            }
            $group = $this->projector->project($finding, $projection);
            if ($group['questions'] === []) {
                continue;
            }
            foreach ($group['questions'] as &$question) {
                $target = $question['target'] ?? [];
                $entity = $entities[$target['entity_id'] ?? ''] ?? null;
                $previous = $facts[$target['entity_id'] ?? ''][$question['input_fact_type']] ?? [];
                $writable = $current && self::editable($session) && $entity instanceof Entity
                    && $entity->sourceVersion === ($target['entity_source_version'] ?? null)
                    && count($previous) <= 1
                    && ($previous === [] || $previous[0]->sourceVersion === $entity->sourceVersion);
                $question['can_answer'] = $writable;
                if ($writable) {
                    $question['answer_contract'] = [...$question['input_contract'],
                        'maximum' => '1000000000000', 'decimal_places' => $question['input_contract']['unit'] === 'item' ? 0 : 4];
                }
                if ($entity instanceof Entity) {
                    $name = $entity->attributes['name'] ?? $entity->attributes['properties']['name'] ?? null;
                    $question['target_label'] = is_string($name) && trim($name) !== '' && mb_strlen($name) <= 160
                        ? $name : trans_message('estimate_generation.prerequisites.source_object');
                }
            }
            unset($question);
            $groups[] = ['finding_key' => $finding->stableKey,
                'title' => trans_message('estimate_generation.planning.completeness.'.$finding->ruleId.'.impact'), ...$group];
        }

        return ['contract_version' => 'estimate-prerequisite-backlog:v1', 'session_id' => (int) $session->id,
            'state_version' => (int) $session->state_version, 'needs_refresh' => ! $current,
            'can_refresh' => self::editable($session), 'groups' => $groups, 'cost_units' => '0'];
    }
}
