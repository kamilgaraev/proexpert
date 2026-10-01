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
            ->unique('id')
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
        $actor = \App\Models\User::find($context->userId);
        if ($actor === null || ! app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->canReadDomain($actor, $context->organizationId, 'finance')) {
            return ['status' => 'unavailable'];
        }
        $policy = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class);
        $workType = $policy->entityTypeForTable('completed_works');
        $paymentType = $policy->entityTypeForTable('payments');
        $performedAmount = null;
        $paidAmount = null;
        $pendingActsAmount = null;
        $pendingActCurrencies = [];

        if ($workType !== null && $policy->entityQuery($actor, $context->organizationId, $workType) !== null
            && Schema::hasTable('completed_works') && Schema::hasColumn('completed_works', 'total_amount')) {
            $performedAmount = (float) $this->scopedTable($context, 'completed_works')
                ->where('status', 'confirmed')
                ->whereBetween('completion_date', [$context->from->toDateString(), $context->to->toDateString()])
                ->sum(DB::raw('COALESCE(total_amount, quantity * COALESCE(price, 0), 0)'));
        }

        if ($paymentType !== null && $policy->entityQuery($actor, $context->organizationId, $paymentType) !== null
            && Schema::hasTable('payments') && Schema::hasColumn('payments', 'organization_id') && Schema::hasColumn('payments', 'amount')) {
            $paidAmount = (float) $this->scopedTable($context, 'payments')
                ->whereBetween('created_at', [$context->from, $context->to])
                ->sum('amount');
        }

        if (Schema::hasTable('contract_performance_acts') && Schema::hasColumn('contract_performance_acts', 'status')) {
            $amountExpression = Schema::hasColumn('contract_performance_acts', 'total_amount') ? 'COALESCE(total_amount, amount, 0)' : 'amount';
            $currencyExpression = Schema::hasColumn('contract_performance_acts', 'currency') ? "UPPER(NULLIF(TRIM(currency), ''))" : 'NULL';
            $pending = $this->scopedTable($context, 'contract_performance_acts')
                ->whereIn('contract_id', $this->scopedTable($context, 'contracts')->select('contracts.id'))
                ->where('is_approved', false)
                ->whereIn('status', ['draft', 'pending_approval', 'pending', 'approval_pending', 'waiting_signature'])
                ->selectRaw("{$currencyExpression} AS currency, SUM({$amountExpression}) AS amount")
                ->groupByRaw($currencyExpression)->orderBy('currency')->get();
            $pendingActCurrencies = $pending->map(static fn ($row): array => ['currency' => $row->currency, 'amount' => (float) $row->amount])->all();
            $pendingActsAmount = $pending->isEmpty() ? 0.0 : ($pending->count() === 1 && $pending->first()->currency === 'RUB' ? (float) $pending->first()->amount : null);
        }

        $deviation = $performedAmount !== null && $paidAmount !== null ? max($performedAmount - $paidAmount, 0) : null;

        return [
            'performed_amount' => $performedAmount,
            'paid_amount' => $paidAmount,
            'pending_acts_amount' => $pendingActsAmount,
            'pending_acts_amounts_by_currency' => $pendingActCurrencies,
            'currency' => 'RUB',
            'deviation_items' => $deviation !== null && $deviation > 0 ? [[
                'title' => 'Выполнение опережает оплату',
                'amount' => $deviation,
                'status' => $deviation > 100000 ? 'warning' : 'info',
            ]] : [],
        ];
    }

    private function projectsQuery(ProjectPulseContext $context)
    {
        $actor = \App\Models\User::find($context->userId);
        if ($actor === null) {
            return Project::query()->whereRaw('1 = 0');
        }
        $query = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->entityQuery($actor, $context->organizationId, 'project') ?? Project::query()->whereRaw('1 = 0');
        return $query->when($context->projectId !== null, fn ($query) => $query->whereKey($context->projectId));
    }

    private function scopedTable(ProjectPulseContext $context, string $table)
    {
        $actor = \App\Models\User::find($context->userId);
        $query = DB::table($table);
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }
        app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->scopeTable($query, $actor, $context->organizationId, $table, true);
        return $query
            ->when(Schema::hasColumn($table, 'organization_id'), fn ($query) => $query->where($table . '.organization_id', $context->organizationId))
            ->when(Schema::hasColumn($table, 'deleted_at'), fn ($query) => $query->whereNull($table . '.deleted_at'))
            ->when($context->projectId !== null && $table !== 'contract_performance_acts' && Schema::hasColumn($table, 'project_id'), function ($query) use ($context, $table): void {
                $query->where($table . '.project_id', $context->projectId);
            });
    }
}
