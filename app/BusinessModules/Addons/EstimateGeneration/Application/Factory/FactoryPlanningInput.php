<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use Illuminate\Database\Connection;
use LogicException;

final readonly class FactoryPlanningInput
{
    public const CONTRACT = 'factory-plan-inspection:v1';

    public function __construct(private Connection $database, private FactoryInspectionInput $inputs,
        private ProjectModelRepository $models, private TechnologySystemCatalog $systems,
        private CompletenessRuleCatalog $rules) {}

    public function capture(EstimateGenerationSession $session): array
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('factory_planning_requires_outer_transaction');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['project-understanding:'.implode(':', $scope)]);
        $catalogs = ['technology_version' => $this->systems->version, 'technology_hash' => $this->systems->contentHash,
            'rules_version' => $this->rules->version, 'rules_hash' => $this->rules->contentHash];
        $catalogFingerprint = hash('sha256', CanonicalPipelineJson::encode($catalogs));
        if (! $this->withinPlanningBudget($scope)) {
            return ['within_budget' => false, 'limitations' => ['planning_budget_exceeded'],
                'catalog_fingerprint' => $catalogFingerprint, 'catalog_refs' => $catalogs,
                'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([self::CONTRACT,
                    (int) $session->state_version, $session->input_payload ?? [], $catalogs, 'planning_budget_exceeded']))];
        }
        $input = $this->inputs->capture($session);
        if (! $input['within_budget']) {
            return [...$input, 'catalog_fingerprint' => $catalogFingerprint, 'catalog_refs' => $catalogs];
        }
        $technology = $this->models->currentTechnologyRecommendations(...$scope);
        $completeness = $this->models->currentCompleteness(...$scope);
        $projections = json_decode(json_encode([$technology, $completeness], JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);

        return [...$input, 'input_fingerprint' => $input['fingerprint'],
            'fingerprint' => hash('sha256', CanonicalPipelineJson::encode([self::CONTRACT, $input['fingerprint'], $catalogs, $projections])),
            'catalog_fingerprint' => $catalogFingerprint, 'catalog_refs' => $catalogs,
            'technology' => $technology, 'completeness' => $completeness];
    }

    private function withinPlanningBudget(array $scope): bool
    {
        $technology = 'estimate_generation_technology_planning_runs';
        $completeness = 'estimate_generation_completeness_runs';
        $recommendations = 'estimate_generation_technology_recommendations';
        $findings = 'estimate_generation_completeness_findings';
        $impacts = $this->database->table($findings.' as record')
            ->join($completeness.' as root', 'root.id', '=', 'record.completeness_run_id')
            ->where('root.organization_id', $scope[0])->where('root.project_id', $scope[1])
            ->where('root.session_id', $scope[2])->where('root.is_current', true)->select('record.impact')->limit(51);
        $impactBudget = $this->database->query()->fromSub($impacts, 'bounded')
            ->selectRaw('count(*) AS rows, COALESCE(sum(octet_length(impact)), 0) AS bytes')->first();
        if ((int) $impactBudget->rows > 50 || (int) $impactBudget->bytes > 8388608) {
            return false;
        }
        $queries = [
            [$this->database->table($technology.' as root')->select('root.*'), 1],
            [$this->database->table($recommendations.' as record')->join($technology.' as root', 'root.id', '=', 'record.planning_run_id')->select('record.*'), 50],
            [$this->database->table('estimate_generation_technology_recommendation_options as record')
                ->join($recommendations.' as parent', 'parent.id', '=', 'record.recommendation_id')
                ->join($technology.' as root', 'root.id', '=', 'parent.planning_run_id')->select('record.*'), 200],
            [$this->database->table($completeness.' as root')->select('root.*'), 1],
            [$this->database->table($findings.' as record')->join($completeness.' as root', 'root.id', '=', 'record.completeness_run_id')->select('record.*'), 50],
            [$this->database->table('estimate_generation_technology_work_packages as record')
                ->join($findings.' as parent', 'parent.id', '=', 'record.completeness_finding_id')
                ->join($completeness.' as root', 'root.id', '=', 'parent.completeness_run_id')->select('record.*'), 50],
        ];
        $bytes = 0;
        foreach ($queries as [$query, $cap]) {
            $query->where('root.organization_id', $scope[0])->where('root.project_id', $scope[1])
                ->where('root.session_id', $scope[2])->where('root.is_current', true)->limit($cap + 1);
            $aggregate = $this->database->query()->fromSub($query, 'bounded')
                ->selectRaw('count(*) AS rows, COALESCE(sum(octet_length(row_to_json(bounded)::text)), 0) AS bytes')->first();
            $bytes += (int) $aggregate->bytes;
            if ((int) $aggregate->rows > $cap || $bytes > 8388608) {
                return false;
            }
        }

        return true;
    }
}
