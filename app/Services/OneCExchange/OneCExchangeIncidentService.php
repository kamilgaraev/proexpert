<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OneCExchangeIncidentService
{
    private const TYPE = 'one_c_exchange_incident';
    private const NOTIFICATION_TYPE = 'one_c_exchange';
    private const CHANNELS = ['in_app', 'websocket'];

    public function __construct(
        private readonly OneCExchangeMonitoringService $monitoring,
        private readonly NotificationService $notifications,
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function incidents(int $organizationId, array $filters): array
    {
        $monitoring = $this->monitoring->monitoring($organizationId, $filters);

        return [
            'generated_at' => $monitoring['generated_at'] ?? null,
            'summary' => $monitoring['notification_summary'] ?? [
                'total_count' => 0,
                'critical_count' => 0,
                'warning_count' => 0,
                'info_count' => 0,
                'overdue_count' => 0,
            ],
            'incidents' => $monitoring['incidents'] ?? [],
            'runbook' => $monitoring['runbook'] ?? [],
        ];
    }

    public function notify(int $organizationId, array $filters): array
    {
        $payload = $this->incidents($organizationId, $filters);
        $recipients = $this->recipients($organizationId);
        $sent = 0;
        $skipped = 0;

        foreach ($payload['incidents'] as $incident) {
            $priority = (string) ($incident['notification_priority'] ?? 'normal');
            $incidentKey = (string) ($incident['key'] ?? $incident['id']);

            if (!in_array($priority, ['critical', 'high'], true)) {
                $skipped++;
                continue;
            }

            foreach ($recipients as $recipient) {
                if ($this->alreadyNotified($recipient, $incidentKey, $organizationId)) {
                    $skipped++;
                    continue;
                }

                try {
                    $this->notifications->send(
                        $recipient,
                        self::TYPE,
                        $this->notificationData($incident),
                        self::NOTIFICATION_TYPE,
                        $priority === 'critical' ? 'critical' : 'high',
                        self::CHANNELS,
                        $organizationId
                    );
                    $sent++;
                } catch (Throwable $exception) {
                    Log::warning('[OneCExchange] Failed to send incident notification', [
                        'organization_id' => $organizationId,
                        'user_id' => $recipient->id,
                        'operation_id' => $incident['operation']['id'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return [
            'sent_count' => $sent,
            'skipped_count' => $skipped,
            'recipients_count' => $recipients->count(),
            'incidents_count' => count($payload['incidents']),
            'generated_at' => $payload['generated_at'],
        ];
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
            'incident_key' => (string) ($incident['key'] ?? $incident['id']),
            'scenario' => $incident['scenario'],
            'severity' => $incident['severity'],
            'owner' => $incident['owner'],
            'next_action' => $incident['next_action'],
            'response_deadline_at' => $incident['response_deadline_at'],
            'operation_id' => $operation['id'] ?? null,
            'operation_key' => $operation['operation_key'] ?? null,
            'safe_error_code' => $operation['safe_error_code'] ?? null,
            'target_route' => '/integrations/1c',
            'actions' => $incident['actions'] ?? [],
        ];
    }

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
            ->filter(fn (User $user): bool => $this->authorization->can($user, 'one_c_exchange.view', [
                'organization_id' => $organizationId,
            ]))
            ->values();
    }

    private function alreadyNotified(User $user, string $fingerprint, int $organizationId): bool
    {
        return Notification::query()
            ->where('type', self::TYPE)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('data->incident_key', $fingerprint)
            ->exists();
    }
}
