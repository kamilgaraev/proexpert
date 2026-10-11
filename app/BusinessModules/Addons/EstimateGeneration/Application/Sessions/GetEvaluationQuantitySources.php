<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NativeQuantityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

final readonly class GetEvaluationQuantitySources
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private NativeQuantityFactEligibility $eligibility) {}

    public function handle(User $actor, int $projectId, int $sessionId, string $workKey, string $search = '', int $page = 1, int $perPage = 25): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 50 || mb_strlen($search) > 100 || trim($workKey) === '' || strlen($workKey) > 160) {
            throw ValidationException::withMessages(['source' => 'Укажите работу и допустимые параметры поиска.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $workKey, $search, $page, $perPage): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $item = (new EvaluationWorkItemLocator)->find($session->draft_payload ?? [], $session->input_payload ?? [], $workKey);
            $snapshot = $this->models->snapshotForPlanning((int) $session->organization_id, $projectId, $sessionId, 10001)['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['source' => 'Разделите проект: предел фактов превышен.']);
            }
            $evidence = array_column($snapshot->evidence, null, 'id');
            $labels = [];
            foreach ($snapshot->entities as $entity) {
                $name = $entity->attributes['name'] ?? $entity->attributes['label'] ?? null;
                $labels[$entity->id] = is_string($name) && trim($name) !== '' ? $name : 'Объект из документа';
            }
            $rows = [];
            foreach ($snapshot->facts as $fact) {
                $proof = $this->eligibility->evidence($fact, $evidence, (string) ($item['unit'] ?? ''));
                $entityLabel = $labels[$fact->entityId] ?? 'Объект из документа';
                if ($proof === null || ($search !== '' && mb_stripos($entityLabel.' '.$fact->type.' '.($proof->nativeReference ?? ''), $search) === false)) {
                    continue;
                }
                $rows[] = ['source_fact_id' => $fact->id, 'source_fact_version' => $fact->version,
                    'source_value_hash' => DirectQuantityBinding::valueHash($fact->value), 'value' => (string) $fact->value, 'unit' => $fact->unit,
                    'entity_id' => $fact->entityId, 'entity_label' => $entityLabel,
                    'parameter' => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::parameter($fact->type), 'source_version' => $fact->sourceVersion,
                    'evidence' => ['id' => $proof->id, 'source_artifact_id' => $proof->sourceArtifactId, 'page' => $proof->page,
                        'region' => $proof->region, 'native_reference' => $proof->nativeReference, 'source_reference' => $proof->sourceReference]];
            }
            usort($rows, static fn (array $left, array $right): int => [$left['entity_id'], $left['parameter'], $left['source_fact_id']]
                <=> [$right['entity_id'], $right['parameter'], $right['source_fact_id']]);
            $total = count($rows);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $currentPage = min($page, $lastPage);

            return ['sources' => array_slice($rows, ($currentPage - 1) * $perPage, $perPage), 'work_item_key' => $workKey,
                'work_unit' => $item['unit'] ?? null, 'state_version' => (int) $session->state_version, 'cost_units' => '0',
                'pagination' => ['current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total]];
        }, 3);
    }
}
