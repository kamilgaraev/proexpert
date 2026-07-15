<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use DomainException;
use Illuminate\Support\Str;

use function trans_message;

final class NotificationPresenter
{
    public function present(Notification $notification): array
    {
        $target = $this->target($notification);

        $payload = $notification->toArray();
        unset($payload['targets'], $payload['analytics'], $payload['read_at']);

        $payload['read_at'] = $target->read_at?->toJSON();
        $payload['dismissed_at'] = $target->dismissed_at?->toJSON();

        return $payload;
    }

    public function presentForCustomer(Notification $notification): array
    {
        $target = $this->target($notification);
        $data = is_array($notification->data) ? $notification->data : [];
        $isUnread = $target->read_at === null;

        return [
            'id' => (string) $notification->id,
            'title' => $this->firstNonEmptyString([
                $data['title'] ?? null,
                $data['subject'] ?? null,
                $data['name'] ?? null,
            ]) ?? Str::headline((string) ($notification->notification_type ?: $notification->type ?: trans_message(
                'notifications.customer_default_title'
            ))),
            'description' => $this->firstNonEmptyString([
                $data['message'] ?? null,
                $data['description'] ?? null,
                $data['body'] ?? null,
                $data['text'] ?? null,
            ]) ?? trans_message('notifications.customer_default_description'),
            'eventType' => $notification->notification_type ?: $notification->type,
            'createdAtLabel' => $notification->created_at?->format('d.m.Y H:i'),
            'created_at' => $notification->created_at?->toISOString(),
            'tone' => $this->customerTone((string) $notification->priority, $isUnread),
            'statusLabel' => trans_message(
                $isUnread ? 'notifications.status_unread' : 'notifications.status_read'
            ),
            'isUnread' => $isUnread,
            'project' => isset($data['project']) && is_array($data['project']) ? $data['project'] : null,
            'related_entity' => isset($data['related_entity']) && is_array($data['related_entity'])
                ? $data['related_entity']
                : null,
            'priority' => $notification->priority ?: 'normal',
        ];
    }

    private function target(Notification $notification): NotificationTarget
    {
        $target = $notification->getRelation('targets')->first();

        if (! $target instanceof NotificationTarget) {
            throw new DomainException('Current notification target is not loaded');
        }

        return $target;
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function customerTone(string $priority, bool $isUnread): string
    {
        return match ($priority) {
            'critical', 'high' => 'warning',
            'low' => $isUnread ? 'success' : 'neutral',
            default => $isUnread ? 'primary' : 'neutral',
        };
    }
}
