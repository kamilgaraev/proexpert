<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DescriptionNumericProposals;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

final readonly class GetDescriptionNumericProposals
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models) {}

    public function handle(User $actor, int $projectId, int $sessionId): array
    {
        return $this->database->transaction(function () use ($actor, $projectId, $sessionId): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $available = config('estimate-generation.universal_enabled', false) && ($session->input_payload['evaluation_mode'] ?? null) === 'universal';
            $result = ['available' => $available, 'state_version' => (int) $session->state_version, 'cost_units' => '0', 'proposals' => []];
            if (! $available) {
                return $result;
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['description' => 'Разделите состав: предел фактов сессии превышен.']);
            }
            $description = (string) ($session->input_payload['description'] ?? '');
            $sources = DescriptionNumericProposals::sourceFacts($snapshot, $description);
            $decisions = $sources === [] ? [] : $this->models->decisionsForSelectedFacts(...[...$scope, array_column($sources, 'id')]);
            $result['proposals'] = DescriptionNumericProposals::fromSnapshot($snapshot, $description, $decisions);

            return $result;
        });
    }
}
