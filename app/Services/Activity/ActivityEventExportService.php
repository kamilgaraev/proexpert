<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\DTOs\Activity\ActivityEventFilters;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ActivityEventExportService
{
    public function __construct(
        private readonly ActivityEventQueryService $queryService,
    ) {}

    public function csv(int $organizationId, ActivityEventFilters $filters): StreamedResponse
    {
        $rows = $this->queryService->exportRows($organizationId, $filters);

        return response()->streamDownload(static function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Дата и время', 'Пользователь', 'Действие', 'Модуль', 'Объект', 'Проект', 'Результат', 'Описание'], ';');

            foreach ($rows as $event) {
                fputcsv($handle, [
                    $event->occurred_at?->format('d.m.Y H:i:s'),
                    $event->actor_name ?: 'Система',
                    $event->title,
                    $event->module,
                    $event->subject_label,
                    $event->project?->name,
                    $event->result,
                    $event->description,
                ], ';');
            }

            fclose($handle);
        }, 'activity-events-' . now()->format('Y-m-d-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
