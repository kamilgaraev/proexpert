<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectPulseFactCollector
{
    public function __construct(
        private readonly ProjectPulseFactSourceRegistry $sourceRegistry,
    ) {
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return $this->sourceRegistry
            ->all()
            ->flatMap(fn (ProjectPulseFactSourceInterface $source) => $source->collect($context))
            ->take((int) config('ai-assistant.project_pulse.limits.facts_total', 250))
            ->values();
    }

    public function metrics(ProjectPulseContext $context, Collection $facts): array
    {
        $projectsQuery = $this->projectsQuery($context);

        return [
            [
                'key' => 'active_projects',
                'label' => 'Активные проекты',
                'value' => (clone $projectsQuery)->where('status', 'active')->count(),
                'tone' => 'primary',
            ],
            [
                'key' => 'critical_facts',
                'label' => 'Критичные события',
                'value' => $facts->where('priority', 'critical')->count(),
                'tone' => 'critical',
            ],
            [
                'key' => 'warning_facts',
                'label' => 'Требуют внимания',
                'value' => $facts->where('priority', 'warning')->count(),
                'tone' => 'warning',
            ],
            [
                'key' => 'daily_activity',
                'label' => 'События за период',
                'value' => $facts->whereNotNull('occurredAt')->count(),
                'tone' => 'neutral',
            ],
        ];
    }

    public function finance(ProjectPulseContext $context): array
    {
        $performedAmount = 0.0;
        $paidAmount = 0.0;

        if (Schema::hasTable('completed_works') && Schema::hasColumn('completed_works', 'total_amount')) {
            $performedAmount = (float) $this->scopedTable($context, 'completed_works')
                ->whereBetween('completion_date', [$context->from->toDateString(), $context->to->toDateString()])
                ->sum('total_amount');
        }

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'organization_id') && Schema::hasColumn('payments', 'amount')) {
            $paidAmount = (float) $this->scopedTable($context, 'payments')
                ->whereBetween('created_at', [$context->from, $context->to])
                ->sum('amount');
        }

        $deviation = max($performedAmount - $paidAmount, 0);

        return [
            'performed_amount' => $performedAmount,
            'paid_amount' => $paidAmount,
            'pending_acts_amount' => 0.0,
            'deviation_items' => $deviation > 0 ? [[
                'title' => 'Выполнение опережает оплату',
                'amount' => $deviation,
                'status' => $deviation > 100000 ? 'warning' : 'info',
            ]] : [],
        ];
    }

    private function projectsQuery(ProjectPulseContext $context)
    {
        return Project::query()
            ->where('organization_id', $context->organizationId)
            ->when($context->projectId !== null, fn ($query) => $query->whereKey($context->projectId));
    }

    private function scopedTable(ProjectPulseContext $context, string $table)
    {
        return DB::table($table)
            ->where($table . '.organization_id', $context->organizationId)
            ->when($context->projectId !== null && Schema::hasColumn($table, 'project_id'), function ($query) use ($context, $table): void {
                $query->where($table . '.project_id', $context->projectId);
            });
    }
}
