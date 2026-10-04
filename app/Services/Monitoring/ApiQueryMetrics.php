<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Database\Events\QueryExecuted;

final class ApiQueryMetrics
{
    public const REQUEST_ATTRIBUTE = 'most.api_query_metrics';

    private int $count = 0;
    private float $total = 0;
    private float $maximum = 0;
    private array $groups = [];

    public static function recordQuery(Request $request, QueryExecuted $query): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite((float) $query->time) || $query->time < 0) { return; }
        self::record($request, (float) $query->time);
        $sql = strtolower($query->sql);
        $group = match (true) {
            str_starts_with($sql, 'set '), str_contains($sql, "set_config('statement_timeout'") => 'settings',
            str_contains($sql, 'pg_catalog'), str_contains($sql, 'information_schema'), str_contains($sql, 'from pg_attribute ') => 'schema',
            str_contains($sql, 'stored_count'), str_contains($sql, 'expected_count') => 'rag_counts',
            preg_match('/\b(?:from|join)\s+"?role_conditions\b/', $sql) === 1 => 'role_conditions',
            preg_match('/\b(?:from|join)\s+"?authorization_contexts\b/', $sql) === 1 => 'contexts',
            preg_match('/\b(?:from|join)\s+"?ai_rag_sources\b/', $sql) === 1 => 'rag_sources',
            preg_match('/\b(?:from|join)\s+"?(?:files|ai_assistant_documents)\b/', $sql) === 1 => 'documents',
            default => 'other',
        };
        $metrics->groups[$group]['count'] = ($metrics->groups[$group]['count'] ?? 0) + 1;
        $metrics->groups[$group]['total_ms'] = ($metrics->groups[$group]['total_ms'] ?? 0) + (float) $query->time;
    }

    public static function record(Request $request, float $milliseconds): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }

        $metrics->count++;
        $metrics->total += $milliseconds;
        $metrics->maximum = max($metrics->maximum, $milliseconds);
    }

    public function summary(): array
    {
        $groups = $this->groups;
        foreach ($groups as &$group) { $group['total_ms'] = round($group['total_ms'], 2); }

        return [
            'sql_count' => $this->count,
            'sql_total_ms' => round($this->total, 2),
            'sql_max_ms' => round($this->maximum, 2),
            'sql_groups' => $groups,
        ];
    }
}
