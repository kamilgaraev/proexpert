<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

/** Scoped numeric inputs, without inferred dimensions or a promise that incomplete geometry is calculable. */
final readonly class GetEvaluationScenarioParameters
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $stateVersion, int $page = 1, string $search = ''): array
    {
        if ($stateVersion < 0 || $page < 1 || $page > 100000 || mb_strlen($search) > 100) {
            throw ValidationException::withMessages(['parameters' => 'Укажите допустимую версию и параметры поиска.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $stateVersion, $page, $search): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->sharedLock()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            if ($session->state_version !== $stateVersion || $session->status->isTerminal()) {
                throw new StaleEstimateGenerationState($sessionId, $stateVersion);
            }
            $snapshot = $this->models->snapshotForPlanning((int) $session->organization_id, $projectId, $sessionId, 10001)['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['parameters' => 'Разделите проект: предел фактов превышен.']);
            }
            $entities = array_column($snapshot->entities, null, 'id');
            $scope = array_fill_keys(array_filter(array_column($session->draft_payload['evaluation_result']['scenarios'][0]['positions'] ?? [], 'entity_id'), 'is_string'), true);
            $rows = [];
            foreach ($snapshot->facts as $fact) {
                if (! isset($scope[$fact->entityId]) || $fact->status !== 'confirmed'
                    || ! in_array($fact->origin, ['document', 'user_input', 'user_assumption'], true)
                    || preg_match('/\Awork_quantity_[a-f0-9]{48}\z/', $fact->type) === 1
                    || ! is_string($fact->unit) || WorkQuantityInputUnit::parse($fact->unit) === null
                    || (! is_string($fact->value) && ! is_int($fact->value)) || ! OrdinaryEstimateDecimal::fitsQuantity($fact->value)) {
                    continue;
                }
                $entity = $entities[$fact->entityId] ?? null;
                $label = $entity?->attributes['name'] ?? $entity?->attributes['label'] ?? $entity?->attributes['identity']['room_name'] ?? 'Объект';
                $parameter = FactVocabulary::parameter($fact->type);
                if (! is_string($label)) {
                    $label = 'Объект';
                }
                if ($search !== '' && mb_stripos($label.' '.$parameter, $search) === false) {
                    continue;
                }
                $rows[] = ['source_fact_id' => $fact->id, 'source_fact_version' => $fact->version,
                    'source_value_hash' => DirectQuantityBinding::valueHash($fact->value), 'entity_id' => $fact->entityId,
                    'entity_label' => $label, 'parameter' => $parameter, 'value' => (string) $fact->value,
                    'unit' => $fact->unit, 'origin' => $fact->origin, 'source_version' => $fact->sourceVersion];
            }
            usort($rows, static fn (array $a, array $b): int => [$a['entity_id'], $a['parameter'], $a['source_fact_id']] <=> [$b['entity_id'], $b['parameter'], $b['source_fact_id']]);
            $total = count($rows);
            $last = max(1, (int) ceil($total / 25));
            $current = min($page, $last);

            return ['parameters' => array_slice($rows, ($current - 1) * 25, 25), 'state_version' => $stateVersion, 'cost_units' => '0',
                'pagination' => ['current_page' => $current, 'last_page' => $last, 'per_page' => 25, 'total' => $total]];
        });
    }
}
