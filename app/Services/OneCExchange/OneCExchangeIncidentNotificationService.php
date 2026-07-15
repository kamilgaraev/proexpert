<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\OneCExchangeOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OneCExchangeIncidentNotificationService
{
    private const TYPE = 'one_c_exchange_incident';

    private const NOTIFICATION_TYPE = 'one_c_exchange';

    private const CHANNELS = ['in_app', 'websocket'];

    public function __construct(
        private readonly OneCExchangeIncidentRuleResolver $resolver,
        private readonly NotificationService $notifications,
        private readonly AuthorizationService $authorization,
    ) {}

    public function notifyOperation(OneCExchangeOperation $operation): void
    {
        try {
            $incident = $this->resolver->resolveOperation($operation, CarbonImmutable::now());

            if ($incident === null) {
                return;
            }

            foreach ($this->recipients((int) $operation->organization_id) as $user) {
                if ($this->alreadySent($user, (int) $operation->organization_id, (string) $incident['key'])) {
                    continue;
                }

                $this->notifications->send(
                    $user,
                    self::TYPE,
                    $this->notificationData($incident),
                    self::NOTIFICATION_TYPE,
                    (string) $incident['notification_priority'],
                    self::CHANNELS,
                    (int) $operation->organization_id,
                    requiredPermissions: ['one_c_exchange.view'],
                    interfaces: ['admin'],
                );
            }
        } catch (Throwable $exception) {
            Log::warning('one_c_exchange.incident_notification_failed', [
                'operation_id' => $operation->id,
                'organization_id' => $operation->organization_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notificationData(array $incident): array
    {
        $operation = is_array($incident['operation'] ?? null) ? $incident['operation'] : null;

        return [
            'type' => self::TYPE,
            'force_send' => true,
            'title' => $incident['title'],
            'message' => $incident['message'],
            'category' => 'one_c_exchange',
            'interface' => 'admin',
            'incident_key' => $incident['key'],
            'scenario' => $incident['scenario'],
            'severity' => $incident['severity'],
            'owner' => $incident['owner'],
            'next_action' => $incident['next_action'],
            'response_deadline_at' => $incident['response_deadline_at'],
            'operation_id' => $operation['id'] ?? null,
            'operation_key' => $operation['operation_key'] ?? null,
            'target_route' => '/integrations/1c',
            'actions' => $incident['actions'],
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(int $organizationId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('organizations', static function ($query) use ($organizationId): void {
                $query
                    ->where('organizations.id', $organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->get()
            ->filter(fn (User $user): bool => $this->authorization->can(
                $user,
                'one_c_exchange.view',
                ['organization_id' => $organizationId]
            ))
            ->values();
    }

    private function alreadySent(User $user, int $organizationId, string $incidentKey): bool
    {
        return Notification::query()
            ->where('type', self::TYPE)
            ->where('organization_id', $organizationId)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->latest()
            ->limit(25)
            ->get()
            ->contains(static function (Notification $notification) use ($incidentKey): bool {
                $data = is_array($notification->data) ? $notification->data : [];

                return ($data['incident_key'] ?? null) === $incidentKey;
            });
    }
}
