<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AIUsageReportService
{
    private const USER_REQUEST_OPERATION = 'assistant_chat';

    private const ESTIMATE_GENERATION_OPERATION_PREFIX = 'estimate_generation';

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        [$from, $to] = $this->period($filters);

        if (! Schema::hasTable('ai_usage_records')) {
            return $this->emptySummary($from, $to);
        }

        $baseQuery = $this->baseQuery($filters, $from, $to);
        $summary = $this->totals(clone $baseQuery);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => $summary,
            'estimate_generation' => $this->estimateGeneration(clone $baseQuery),
            'organizations' => $this->organizations(clone $baseQuery),
            'models' => $this->models(clone $baseQuery),
            'operations' => $this->operations(clone $baseQuery),
            'daily' => $this->daily(clone $baseQuery),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = DB::table('ai_usage_records as u')
            ->whereBetween('u.occurred_at', [$from->startOfDay(), $to->endOfDay()]);

        if (isset($filters['organization_id']) && is_numeric($filters['organization_id'])) {
            $query->where('u.organization_id', (int) $filters['organization_id']);
        }

        foreach (['provider', 'model', 'operation'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where("u.{$field}", $value);
            }
        }

        return $query;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $filters): array
    {
        $from = $this->parseDate($filters['date_from'] ?? null) ?? CarbonImmutable::now()->subDays(6);
        $to = $this->parseDate($filters['date_to'] ?? null) ?? CarbonImmutable::now();

        if ($from->greaterThan($to)) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(Builder $query): array
    {
        $row = $query
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN u.operation = ? THEN 1 ELSE 0 END), 0) as user_requests_count',
                [self::USER_REQUEST_OPERATION]
            )
            ->selectRaw('COALESCE(SUM(u.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(u.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(u.total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(u.input_cost_rub), 0) as input_cost_rub')
            ->selectRaw('COALESCE(SUM(u.output_cost_rub), 0) as output_cost_rub')
            ->selectRaw('COALESCE(SUM(u.total_cost_rub), 0) as total_cost_rub')
            ->first();

        return [
            'requests_count' => (int) ($row->requests_count ?? 0),
            'user_requests_count' => (int) ($row->user_requests_count ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'input_cost_rub' => $this->money($row->input_cost_rub ?? 0),
            'output_cost_rub' => $this->money($row->output_cost_rub ?? 0),
            'total_cost_rub' => $this->money($row->total_cost_rub ?? 0),
            'currency' => 'RUB',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function estimateGeneration(Builder $query): array
    {
        $row = $this->onlyEstimateGeneration($query)
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw('COALESCE(SUM(u.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(u.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(u.total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(u.total_cost_rub), 0) as total_cost_rub')
            ->first();

        return [
            'requests_count' => (int) ($row->requests_count ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'total_cost_rub' => $this->money($row->total_cost_rub ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function organizations(Builder $query): array
    {
        return $query
            ->leftJoin('organizations as o', 'o.id', '=', 'u.organization_id')
            ->selectRaw('u.organization_id')
            ->selectRaw('COALESCE(o.name, ?) as organization_name', ['Без организации'])
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw('COALESCE(SUM(u.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(u.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(u.total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(u.total_cost_rub), 0) as total_cost_rub')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN u.operation = ? THEN 1 ELSE 0 END), 0) as user_requests_count',
                [self::USER_REQUEST_OPERATION]
            )
            ->groupBy('u.organization_id', 'o.name')
            ->orderByRaw('COALESCE(SUM(u.total_cost_rub), 0) DESC')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'organization_id' => $row->organization_id !== null ? (int) $row->organization_id : null,
                'organization_name' => (string) $row->organization_name,
                'requests_count' => (int) $row->requests_count,
                'user_requests_count' => (int) $row->user_requests_count,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost_rub' => $this->money($row->total_cost_rub),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function models(Builder $query): array
    {
        return $query
            ->selectRaw('u.provider, u.model')
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw('COALESCE(SUM(u.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(u.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(u.total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(u.total_cost_rub), 0) as total_cost_rub')
            ->groupBy('u.provider', 'u.model')
            ->orderByRaw('COALESCE(SUM(u.total_cost_rub), 0) DESC')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'provider' => (string) $row->provider,
                'model' => (string) $row->model,
                'requests_count' => (int) $row->requests_count,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost_rub' => $this->money($row->total_cost_rub),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function operations(Builder $query): array
    {
        return $query
            ->selectRaw('u.operation')
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw('COALESCE(SUM(u.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(u.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(u.total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(u.total_cost_rub), 0) as total_cost_rub')
            ->groupBy('u.operation')
            ->orderByRaw('COALESCE(SUM(u.total_cost_rub), 0) DESC')
            ->get()
            ->map(fn (object $row): array => [
                'operation' => (string) $row->operation,
                'requests_count' => (int) $row->requests_count,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost_rub' => $this->money($row->total_cost_rub),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function daily(Builder $query): array
    {
        return $query
            ->selectRaw('DATE(u.occurred_at) as usage_date')
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN u.operation = ? THEN 1 ELSE 0 END), 0) as user_requests_count',
                [self::USER_REQUEST_OPERATION]
            )
            ->selectRaw('COALESCE(SUM(u.total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(u.total_cost_rub), 0) as total_cost_rub')
            ->groupByRaw('DATE(u.occurred_at)')
            ->orderBy('usage_date')
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->usage_date,
                'requests_count' => (int) $row->requests_count,
                'user_requests_count' => (int) $row->user_requests_count,
                'total_tokens' => (int) $row->total_tokens,
                'total_cost_rub' => $this->money($row->total_cost_rub),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => [
                'requests_count' => 0,
                'user_requests_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'input_cost_rub' => '0.000000',
                'output_cost_rub' => '0.000000',
                'total_cost_rub' => '0.000000',
                'currency' => 'RUB',
            ],
            'estimate_generation' => [
                'requests_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'total_cost_rub' => '0.000000',
            ],
            'organizations' => [],
            'models' => [],
            'operations' => [],
            'daily' => [],
        ];
    }

    private function onlyEstimateGeneration(Builder $query): Builder
    {
        return $query->where('u.operation', 'like', self::ESTIMATE_GENERATION_OPERATION_PREFIX.'%');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
