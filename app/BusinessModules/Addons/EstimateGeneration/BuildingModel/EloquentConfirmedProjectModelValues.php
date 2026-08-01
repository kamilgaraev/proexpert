<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use Illuminate\Database\DatabaseManager;
use stdClass;

/** Builds the effective, evidence-backed project-model projection without mutating its immutable snapshot. */
final readonly class EloquentConfirmedProjectModelValues
{
    public function __construct(
        private DatabaseManager $database,
        private ProjectModelMerger $merger = new ProjectModelMerger,
        private ConfirmedProjectModelProjector $projector = new ConfirmedProjectModelProjector,
    ) {}

    /** @return list<array<string,mixed>> */
    public function forModel(stdClass $model): array
    {
        $scope = [$model->organization_id, $model->project_id, $model->session_id, $model->content_version];
        $entities = [];
        foreach ($this->database->table('estimate_generation_project_model_entities')->where('building_model_id', $model->id)
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])->where('source_version', $scope[3])->orderBy('id')->get() as $row) {
            $entities[] = new ProjectModelEntity((int) $row->building_model_id, (int) $row->organization_id, (int) $row->project_id, (int) $row->session_id, (string) $row->source_version, (string) $row->stable_key, (string) $row->entity_kind, $this->json($row->payload), $row->confidence === null ? null : (float) $row->confidence);
        }
        $assertions = [];
        foreach ($this->database->table('estimate_generation_project_model_assertions as a')->join('estimate_generation_project_model_entities as e', 'e.id', '=', 'a.entity_id')->where('a.building_model_id', $model->id)->where('a.organization_id', $scope[0])->where('a.project_id', $scope[1])->where('a.session_id', $scope[2])->where('a.source_version', $scope[3])->orderBy('a.id')->get(['a.*', 'e.stable_key as entity_stable_key']) as $row) {
            $assertions[] = new ProjectModelAssertion((int) $row->building_model_id, (int) $row->organization_id, (int) $row->project_id, (int) $row->session_id, (string) $row->source_version, (string) $row->stable_key, (string) $row->entity_stable_key, (string) $row->assertion_type, $this->json($row->payload), (float) $row->confidence);
        }
        $bindings = [];
        foreach ($this->database->table('estimate_generation_project_model_evidence_bindings as b')->join('estimate_generation_evidence as ev', 'ev.id', '=', 'b.evidence_id')->join('estimate_generation_project_model_entities as e', 'e.id', '=', 'b.entity_id')->join('estimate_generation_project_model_assertions as a', 'a.id', '=', 'b.assertion_id')->leftJoin('estimate_generation_project_model_corrections as c', 'c.id', '=', 'b.correction_id')->where('b.building_model_id', $model->id)->where('b.organization_id', $scope[0])->where('b.project_id', $scope[1])->where('b.session_id', $scope[2])->where('b.source_version', $scope[3])->whereNull('ev.invalidated_at')->orderBy('b.id')->get(['b.*', 'e.stable_key as entity_stable_key', 'a.stable_key as assertion_stable_key', 'c.stable_key as correction_stable_key', 'ev.source_version as evidence_source_version_actual', 'ev.invalidation_version as evidence_invalidation_version_actual']) as $row) {
            if ((string) $row->evidence_source_version !== (string) $row->evidence_source_version_actual || (int) $row->evidence_invalidation_version !== (int) $row->evidence_invalidation_version_actual) continue;
            $bindings[] = new ProjectModelEvidenceBinding((int) $row->building_model_id, (int) $row->organization_id, (int) $row->project_id, (int) $row->session_id, (string) $row->source_version, (string) $row->entity_stable_key, (string) $row->assertion_stable_key, $row->correction_id === null ? null : (string) $row->correction_stable_key, (int) $row->evidence_id, (string) $row->candidate_source, (string) $row->candidate_value_fingerprint, (string) $row->evidence_source_version, (int) $row->evidence_invalidation_version);
        }
        // Corrections are a chronological reversible chain and are overlaid by the caller.
        // Feeding that history to the candidate merger would incorrectly make a reverted
        // manual value look current.
        $projection = $this->projector->project($this->merger->merge(ProjectModelEntityList::of(...$entities), ProjectModelAssertionList::of(...$assertions), ProjectModelCorrectionList::of(), ProjectModelEvidenceBindingList::of(...$bindings)));
        return array_map(static fn (ProjectModelResolvedValue $value): array => ['entity_stable_key' => $value->entityStableKey, 'assertion_stable_key' => $value->assertionStableKey, 'assertion_type' => $value->assertionType, 'value' => $value->value, 'source' => $value->source, 'correction_stable_key' => $value->correctionStableKey], iterator_to_array($projection->values));
    }

    /** @return array<string,mixed> */ private function json(mixed $value): array { $decoded = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null); if (!is_array($decoded) || array_is_list($decoded)) throw new \UnexpectedValueException('Project model projection is invalid.'); return $decoded; }
}
