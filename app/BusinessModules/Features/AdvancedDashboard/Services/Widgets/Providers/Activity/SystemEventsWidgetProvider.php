<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemEventsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::SYSTEM_EVENTS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $limit = $request->getParam('limit', 100);
        $from = $request->from ?? Carbon::now()->subDays(7);
        $to = $request->to ?? Carbon::now();

        // Собираем события из разных источников
        $contractEvents = $this->getContractEvents($request->organizationId, $from, $to);
        $projectEvents = $this->getProjectEvents($request->organizationId, $from, $to);
        $workEvents = $this->getWorkEvents($request->organizationId, $from, $to);
        
        $allEvents = array_merge($contractEvents, $projectEvents, $workEvents);
        
        // Сортируем по дате
        usort($allEvents, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
        
        return [
            'events' => array_slice($allEvents, 0, $limit),
            'total_count' => count($allEvents),
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'by_type' => $this->groupByType($allEvents),
        ];
    }

    protected function getContractEvents(int $organizationId, Carbon $from, Carbon $to): array
    {
        $contracts = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $contracts->map(fn($c) => [
            'id' => 'contract_' . $c->id,
            'type' => 'contract_created',
            'description' => "Создан контракт №{$c->number}",
            'timestamp' => $c->created_at,
            'metadata' => [
                'contract_id' => $c->id,
                'amount' => $c->total_amount,
            ],
        ])->toArray();
    }

    protected function getProjectEvents(int $organizationId, Carbon $from, Carbon $to): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $projects->map(fn($p) => [
            'id' => 'project_' . $p->id,
            'type' => 'project_created',
            'description' => "Создан проект: {$p->name}",
            'timestamp' => $p->created_at,
            'metadata' => [
                'project_id' => $p->id,
                'status' => $p->status,
            ],
        ])->toArray();
    }

    protected function getWorkEvents(int $organizationId, Carbon $from, Carbon $to): array
    {
        $works = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->select('completed_works.*', 'projects.name as project_name', 'users.name as user_name')
            ->orderByDesc('completed_works.created_at')
            ->limit(50)
            ->get();

        return $works->map(fn($w) => [
            'id' => 'work_' . $w->id,
            'type' => 'work_completed',
            'description' => "Выполнена работа: {$w->user_name} в проекте {$w->project_name}",
            'timestamp' => $w->created_at,
            'metadata' => [
                'work_id' => $w->id,
                'user_id' => $w->user_id,
                'project_id' => $w->project_id,
            ],
        ])->toArray();
    }

    protected function groupByType(array $events): array
    {
        $grouped = [];
        foreach ($events as $event) {
            $type = $event['type'];
            if (!isset($grouped[$type])) {
                $grouped[$type] = 0;
            }
            $grouped[$type]++;
        }
        return $grouped;
    }
}
