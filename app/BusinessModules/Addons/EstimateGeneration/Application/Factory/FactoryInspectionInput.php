<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Database\Connection;
use LogicException;

final readonly class FactoryInspectionInput
{
    private const TABLE_CAPS = [
        'estimate_generation_project_model_entities' => 4096,
        'estimate_generation_project_model_assertions' => 10000,
        'estimate_generation_project_model_fact_evidence' => 20000,
        'estimate_generation_evidence' => 20000,
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
        $withinBudget = $bytes <= 1048576;
        foreach (self::TABLE_CAPS as $table => $cap) {
            $query = $this->database->table($table)->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])->where('session_id', $scope[2]);
            if ($table === 'estimate_generation_ai_role_runs') {
                $query->where('status', 'completed')->whereIn('role', ['arbiter', 'geometry_expert']);
            }
            $query->limit($cap + 1);
            $aggregate = $this->database->query()->fromSub($query, 'bounded')
                ->selectRaw('count(*) AS rows, COALESCE(sum(octet_length(row_to_json(bounded)::text)), 0) AS bytes')->first();
            $bytes += (int) $aggregate->bytes;
            $withinBudget = $withinBudget && (int) $aggregate->rows <= $cap && $bytes <= 16777216;
            if (! $withinBudget) {
                break;
            }
        }
        if (! $withinBudget) {
            return ['within_budget' => false, 'snapshot' => null, 'model_token' => null,
                'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([
                    FactoryInspectionStore::POLICY, (int) $session->state_version, $session->status->value, $payload, 'input_budget_exceeded',
                ]))];
        }
        $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);

        return ['within_budget' => true, 'snapshot' => $capture['snapshot'], 'model_token' => $capture['token'],
            'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([
                FactoryInspectionStore::POLICY, (int) $session->state_version, $session->status->value, $payload, $capture['token'],
            ]))];
    }
}
