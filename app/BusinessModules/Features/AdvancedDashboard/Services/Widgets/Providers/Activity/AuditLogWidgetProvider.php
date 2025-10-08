<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AuditLogWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::AUDIT_LOG;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $limit = $request->getParam('limit', 50);
        $from = $request->from ?? Carbon::now()->subDays(30);
        $to = $request->to ?? Carbon::now();

        // Проверяем наличие таблицы audit_logs или activity_log
        $hasAuditLog = DB::getSchemaBuilder()->hasTable('activity_log');
        
        if ($hasAuditLog) {
            return $this->getFromActivityLog($request->organizationId, $from, $to, $limit);
        }

        // Если нет dedicated audit log, создаем из изменений
        return $this->getFromChanges($request->organizationId, $from, $to, $limit);
    }

    protected function getFromActivityLog(int $organizationId, Carbon $from, Carbon $to, int $limit): array
    {
        $logs = DB::table('activity_log')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'audit_log' => $logs->map(fn($l) => [
                'id' => $l->id,
                'action' => $l->description ?? $l->event,
                'user' => $l->causer_id ? "User #{$l->causer_id}" : 'System',
                'subject' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'timestamp' => $l->created_at,
                'properties' => json_decode($l->properties ?? '{}', true),
            ])->toArray(),
            'total_count' => $logs->count(),
        ];
    }

    protected function getFromChanges(int $organizationId, Carbon $from, Carbon $to, int $limit): array
    {
        // Создаем audit log из изменений в ключевых таблицах
        $changes = [];

        // Новые контракты
        $contracts = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        foreach ($contracts as $c) {
            $changes[] = [
                'action' => 'contract_created',
                'user' => $c->created_by ?? 'System',
                'subject' => 'Contract',
                'subject_id' => $c->id,
                'timestamp' => $c->created_at,
                'description' => "Создан контракт №{$c->number}",
            ];
        }

        // Новые проекты
        $projects = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        foreach ($projects as $p) {
            $changes[] = [
                'action' => 'project_created',
                'user' => 'System',
                'subject' => 'Project',
                'subject_id' => $p->id,
                'timestamp' => $p->created_at,
                'description' => "Создан проект: {$p->name}",
            ];
        }

        // Сортируем по времени
        usort($changes, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

        return [
            'audit_log' => array_slice($changes, 0, $limit),
            'total_count' => count($changes),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
        ];
    }
}
