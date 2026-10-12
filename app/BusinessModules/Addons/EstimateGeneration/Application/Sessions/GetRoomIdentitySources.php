<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

final readonly class GetRoomIdentitySources
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private CurrentRoomIdentitySources $sources) {}

    public function handle(User $actor, int $projectId, int $sessionId, string $search = '', int $page = 1, int $perPage = 25): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 50 || mb_strlen($search) > 100) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_generation.room_identity.search_invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $search, $page, $perPage): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $available = config('estimate-generation.universal_enabled', false) && ($session->input_payload['evaluation_mode'] ?? null) === 'universal';
            $rows = $available ? $this->sources->rows($session) : [];
            if ($search !== '') {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => mb_stripos($row['name'].' '.($row['floor'] ?? '').' '.($row['zone'] ?? ''), $search) !== false));
            }
            $total = count($rows);
            $last = max(1, (int) ceil($total / $perPage));
            $current = min($page, $last);

            return ['sources' => array_slice($rows, ($current - 1) * $perPage, $perPage), 'available' => $available,
                'state_version' => (int) $session->state_version, 'cost_units' => '0',
                'pagination' => ['current_page' => $current, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total]];
        }, 3);
    }
}
