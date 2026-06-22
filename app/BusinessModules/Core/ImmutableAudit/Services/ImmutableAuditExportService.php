<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventFilters;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImmutableAuditExportService
{
    public function __construct(
        private readonly ImmutableAuditQueryService $queryService,
    ) {}

    public function csv(ImmutableAuditEventFilters $filters): StreamedResponse
    {
        $rows = $this->queryService->exportRows($filters);

        return response()->streamDownload(static function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Sequence',
                'Дата события',
                'Домен',
                'Тип события',
                'Действие',
                'Итог',
                'Критичность',
                'Пользователь',
                'Источник',
                'Объект',
                'Correlation ID',
                'Причина',
                'Было',
                'Стало',
                'Изменения',
                'Payload hash',
                'Previous hash',
                'Record hash',
                'Integrity status',
                'Retention until',
            ], ';');

            foreach ($rows as $event) {
                fputcsv($handle, self::row($event), ';');
            }

            fclose($handle);
        }, 'immutable-audit-evidence-' . now()->format('Y-m-d-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private static function row(ImmutableAuditEvent $event): array
    {
        return [
            $event->sequence_id,
            $event->occurred_at?->format('d.m.Y H:i:s'),
            $event->domain,
            $event->event_type,
            $event->action,
            $event->result,
            $event->severity,
            $event->actor_user_id !== null ? 'ID ' . $event->actor_user_id : $event->actor_type,
            $event->source,
            trim(implode(' ', array_filter([$event->subject_type, $event->subject_id, $event->subject_label]))),
            $event->correlation_id,
            $event->reason,
            self::json($event->before_state ?? []),
            self::json($event->after_state ?? []),
            self::json($event->diff ?? []),
            $event->payload_hash,
            $event->previous_hash,
            $event->record_hash,
            $event->integrity_status,
            $event->retention_until?->format('d.m.Y H:i:s'),
        ];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
