<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\DTOs\Activity\ActivityEventData;
use App\Models\Activity\ActivityEvent;
use Illuminate\Support\Facades\Lang;

use function trans_message;

final class ActivityEventPresenter
{
    public function presentForData(ActivityEventData $data): array
    {
        $values = [
            'actor' => $data->actorName ?: trans_message('activity.system_actor'),
            'target' => (string) ($data->context['target_name'] ?? $data->subjectLabel ?? trans_message('activity.unknown_target')),
            'subject' => (string) ($data->subjectLabel ?? trans_message('activity.unknown_subject')),
            'role' => (string) ($data->context['role'] ?? trans_message('activity.unknown_role')),
        ];

        $eventTranslations = Lang::get('activity.events');
        $eventTranslation = is_array($eventTranslations) && isset($eventTranslations[$data->eventType])
            ? $eventTranslations[$data->eventType]
            : [];

        return [
            'title' => $data->title ?: $this->translateText((string) ($eventTranslation['title'] ?? trans_message('activity.default_event.title')), $values),
            'description' => $data->description ?: $this->translateText((string) ($eventTranslation['description'] ?? trans_message('activity.default_event.description')), $values),
        ];
    }

    public function detailsForResource(ActivityEvent $event): array
    {
        $details = [];
        $context = $event->context ?? [];

        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value) || $value === null || $value === '') {
                continue;
            }

            $details[] = [
                'label' => Lang::has("activity.context_labels.{$key}")
                    ? trans_message("activity.context_labels.{$key}")
                    : (string) $key,
                'value' => (string) $value,
            ];
        }

        return $details;
    }

    public function changesForResource(ActivityEvent $event): array
    {
        $changes = $event->changes ?? [];

        if (!isset($changes['fields']) || !is_array($changes['fields'])) {
            return [];
        }

        return array_values(array_map(static function (array $change): array {
            return [
                'field' => (string) ($change['field'] ?? ''),
                'label' => (string) ($change['label'] ?? $change['field'] ?? ''),
                'before' => $change['before'] ?? null,
                'after' => $change['after'] ?? null,
            ];
        }, $changes['fields']));
    }

    private function translateText(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace(':' . $key, (string) $value, $text);
        }

        return $text;
    }
}
