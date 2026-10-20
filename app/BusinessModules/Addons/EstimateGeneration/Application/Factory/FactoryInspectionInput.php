<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use Illuminate\Database\Connection;
use LogicException;

final readonly class FactoryInspectionInput
{
    public const MAX_INPUT_BYTES = 1048576;

    public const MAX_TOTAL_BYTES = 16777216;

    private const TABLE_CAPS = [
        'estimate_generation_project_model_entities' => 4096,
        'estimate_generation_project_model_assertions' => 10000,
        'estimate_generation_project_model_fact_projections' => 10000,
        'estimate_generation_project_model_fact_evidence' => 20000,
        'estimate_generation_evidence' => 20000,
        'estimate_generation_project_model_conflicts' => 32,
        'estimate_generation_project_model_conflict_facts' => 256,
        'estimate_generation_project_model_corrections' => 256,
        'estimate_generation_ai_role_runs' => 512,
        'estimate_generation_documents' => 512,
    ];

    public function __construct(private Connection $database, private ProjectModelRepository $models) {}

    public function capture(EstimateGenerationSession $session): array
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('factory_inspection_requires_outer_transaction');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['project-understanding:'.implode(':', $scope)]);
        $payload = CanonicalPipelineJson::encode($session->input_payload ?? []);
        $bytes = strlen($payload);
        $withinBudget = $bytes <= self::MAX_TOTAL_BYTES
            && strlen(CanonicalPipelineJson::encode(FactoryWorkingInput::fromPayload($session->input_payload ?? []))) <= self::MAX_INPUT_BYTES;
        foreach (self::TABLE_CAPS as $table => $cap) {
            $query = $this->database->table($table)->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])->where('session_id', $scope[2]);
            if ($table === 'estimate_generation_ai_role_runs') {
                $query->where('status', 'completed')->whereIn('role', ['arbiter', 'geometry_expert'])
                    ->select(['role', 'subject_version', 'input_fingerprint']);
            }
            $query->limit($cap + 1);
            $aggregate = $this->database->query()->fromSub($query, 'bounded')
                ->selectRaw('count(*) AS rows, COALESCE(sum(octet_length(row_to_json(bounded)::text)), 0) AS bytes')->first();
            $bytes += (int) $aggregate->bytes;
            $withinBudget = $withinBudget && (int) $aggregate->rows <= $cap && $bytes <= self::MAX_TOTAL_BYTES;
            if (! $withinBudget) {
                break;
            }
        }
        if (! $withinBudget) {
            return $this->unavailable($session, $payload);
        }
        $projections = $this->database->table('estimate_generation_project_model_fact_projections')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2]);
        if ($projections->where('is_current', true)->select('fact_id')->groupBy('fact_id')->havingRaw('count(*) > 1')->exists()) {
            return $this->unavailable($session, $payload, 'additional_review_required');
        }
        $bindings = $this->database->table('estimate_generation_project_model_fact_evidence')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2]);
        if ($bindings->select('fact_id')->groupBy('fact_id')->havingRaw('count(*) > 32')->exists()) {
            return $this->unavailable($session, $payload);
        }
        foreach ([
            ['estimate_generation_project_model_fact_evidence', 'estimate_generation_evidence', 'evidence_id'],
            ['estimate_generation_project_model_conflict_facts', 'estimate_generation_project_model_assertions', 'fact_id'],
        ] as [$links, $records, $foreignKey]) {
            $aggregate = $this->database->table($links.' as link')->join($records.' as record', function ($join) use ($foreignKey): void {
                $join->on('record.id', '=', 'link.'.$foreignKey)->on('record.organization_id', '=', 'link.organization_id')
                    ->on('record.project_id', '=', 'link.project_id')->on('record.session_id', '=', 'link.session_id');
            })->where('link.organization_id', $scope[0])->where('link.project_id', $scope[1])->where('link.session_id', $scope[2])
                ->selectRaw('COALESCE(sum(octet_length(row_to_json(record)::text)), 0) AS bytes')->first();
            $bytes += (int) $aggregate->bytes;
            if ($bytes > self::MAX_TOTAL_BYTES) {
                return $this->unavailable($session, $payload);
            }
        }
        $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')]);
        $serialized = json_encode(['snapshot' => $capture['snapshot'], 'decisions' => $decisions,
            'document_versions' => GeometryInventory::currentDocumentVersions($capture['snapshot'])],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($serialized) > self::MAX_TOTAL_BYTES) {
            return $this->unavailable($session, $payload);
        }
        $snapshotFingerprint = hash('sha256', CanonicalPipelineJson::encode(json_decode($serialized, true, 512, JSON_THROW_ON_ERROR)));

        return ['within_budget' => true, 'snapshot' => $capture['snapshot'], 'model_token' => $capture['token'],
            'limitations' => [],
            'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([
                FactoryInspectionStore::POLICY, FactoryWorkingInput::CONTRACT, \App\BusinessModules\Addons\EstimateGeneration\Quantities\ScopedQuantityInputReadiness::CONTRACT,
                \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope::CONTRACT,
                (int) $session->state_version, $session->status->value, $payload, $capture['token'], $snapshotFingerprint,
            ]))];
    }

    private function unavailable(EstimateGenerationSession $session, string $payload, string $reason = 'input_budget_exceeded'): array
    {
        return ['within_budget' => false, 'snapshot' => null, 'model_token' => null, 'limitations' => [$reason],
            'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([
                FactoryInspectionStore::POLICY, FactoryWorkingInput::CONTRACT, \App\BusinessModules\Addons\EstimateGeneration\Quantities\ScopedQuantityInputReadiness::CONTRACT,
                \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope::CONTRACT,
                (int) $session->state_version, $session->status->value, $payload, $reason,
            ]))];
    }
}
