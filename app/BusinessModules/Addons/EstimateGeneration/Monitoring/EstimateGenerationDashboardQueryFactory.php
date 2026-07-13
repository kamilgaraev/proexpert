<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

final class EstimateGenerationDashboardQueryFactory
{
    public function sessionMetrics(DashboardFilters $filters): OperationalQuery
    {
        [$cohort, $bindings] = $this->cohort($filters);
        $sql = $cohort.<<<'SQL'
, session_metrics AS (
    SELECT COUNT(*)::bigint AS sessions_total,
           COUNT(*) FILTER (WHERE status IN ('estimate_review_required', 'ready_to_apply', 'applied') OR applied_at IS NOT NULL)::bigint AS successful_sessions,
           COUNT(*) FILTER (WHERE applied_at IS NOT NULL)::bigint AS applied_sessions,
           COUNT(*) FILTER (WHERE status IN ('input_review_required', 'estimate_review_required'))::bigint AS current_review_backlog_sessions,
           COALESCE(AVG(EXTRACT(EPOCH FROM (COALESCE(applied_at, state_changed_at, updated_at) - created_at)) * 1000) FILTER (WHERE status IN ('estimate_review_required', 'ready_to_apply', 'applied') OR applied_at IS NOT NULL), 0)::bigint AS average_duration_ms,
           COALESCE(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (COALESCE(applied_at, state_changed_at, updated_at) - created_at)) * 1000) FILTER (WHERE status IN ('estimate_review_required', 'ready_to_apply', 'applied') OR applied_at IS NOT NULL), 0)::bigint AS p95_duration_ms
    FROM filtered_sessions
), document_metrics AS (
    SELECT COUNT(documents.id)::bigint AS documents_total
    FROM estimate_generation_documents AS documents
    JOIN filtered_sessions ON filtered_sessions.id = documents.session_id
)
SELECT session_metrics.*, document_metrics.documents_total
FROM session_metrics CROSS JOIN document_metrics
SQL;

        return new OperationalQuery($sql, $bindings, [
            'sessions_total', 'successful_sessions', 'applied_sessions',
            'current_review_backlog_sessions', 'documents_total', 'average_duration_ms', 'p95_duration_ms',
        ], 1);
    }

    public function usageMetrics(DashboardFilters $filters): OperationalQuery
    {
        [$cohort, $bindings] = $this->cohort($filters);
        $sql = $cohort.<<<'SQL'
SELECT CASE WHEN COUNT(DISTINCT usage.currency) FILTER (WHERE usage.pricing_status = 'available') = 1
            THEN SUM(usage.cost_amount) FILTER (WHERE usage.pricing_status = 'available') END::numeric(20,8) AS total_cost,
       CASE WHEN COUNT(DISTINCT usage.currency) FILTER (WHERE usage.pricing_status = 'available') = 1
            THEN MIN(usage.currency) FILTER (WHERE usage.pricing_status = 'available') END AS currency
FROM estimate_generation_ai_usage AS usage
JOIN filtered_sessions ON filtered_sessions.id = usage.session_id
SQL;

        return new OperationalQuery($sql, $bindings, ['total_cost', 'currency'], 1);
    }

    public function queueHealth(DashboardFilters $filters): OperationalQuery
    {
        [$cohort, $bindings] = $this->cohort($filters);
        $sql = $cohort.<<<'SQL'
SELECT COUNT(*) FILTER (WHERE checkpoints.status = 'running')::bigint AS running_jobs,
       COUNT(*) FILTER (WHERE checkpoints.status = 'running' AND checkpoints.lease_expires_at < CURRENT_TIMESTAMP)::bigint AS stale_jobs,
       COALESCE(MAX(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - checkpoints.created_at))) FILTER (WHERE checkpoints.status = 'running'), 0)::bigint AS oldest_queue_age_seconds
FROM estimate_generation_pipeline_checkpoints AS checkpoints
JOIN filtered_sessions ON filtered_sessions.id = checkpoints.session_id
SQL;

        return new OperationalQuery($sql, $bindings, [
            'running_jobs', 'stale_jobs', 'oldest_queue_age_seconds',
        ], 1);
    }

    public function currencySelection(DashboardFilters $filters): OperationalQuery
    {
        [$cohort, $bindings] = $this->cohort($filters);
        $limit = DashboardFilters::MAX_CURRENCY_SERIES + 1;
        $sql = $cohort.<<<'SQL'
, currency_totals AS (
    SELECT usage.currency,
           SUM(usage.cost_amount)::numeric(20,8) AS total_cost
    FROM estimate_generation_ai_usage AS usage
    JOIN filtered_sessions ON filtered_sessions.id = usage.session_id
    WHERE usage.pricing_status = 'available' AND usage.currency IS NOT NULL
    GROUP BY usage.currency
)
SELECT currency, total_cost, COUNT(*) OVER()::int AS currencies_total
FROM currency_totals
ORDER BY total_cost DESC, currency ASC
SQL;
        $sql .= " LIMIT {$limit}";

        return new OperationalQuery($sql, $bindings, ['currency', 'total_cost', 'currencies_total'], $limit);
    }

    /** @param list<string> $currencies */
    public function costTrend(DashboardFilters $filters, array $currencies): OperationalQuery
    {
        [$cohort, $bindings] = $this->cohort($filters);
        $currencies = array_values(array_slice(array_unique($currencies), 0, DashboardFilters::MAX_CURRENCY_SERIES));
        $rowLimit = DashboardFilters::MAX_DAYS * DashboardFilters::MAX_CURRENCY_SERIES;
        if ($currencies === []) {
            return new OperationalQuery($cohort.' SELECT NULL WHERE FALSE', $bindings, [
                'bucket', 'total_cost', 'currency', 'sessions',
            ], 0);
        }

        $placeholders = implode(', ', array_fill(0, count($currencies), '?'));
        $bindings = [...$bindings, ...$currencies];
        $sql = $cohort.<<<'SQL'
SELECT DATE_TRUNC('day', filtered_sessions.created_at) AS bucket,
       SUM(usage.cost_amount)::numeric(20,8) AS total_cost,
       usage.currency,
       COUNT(DISTINCT filtered_sessions.id)::bigint AS sessions
FROM filtered_sessions
JOIN estimate_generation_ai_usage AS usage ON usage.session_id = filtered_sessions.id
WHERE usage.pricing_status = 'available' AND usage.currency IN (
SQL;
        $sql .= $placeholders.') GROUP BY DATE_TRUNC(\'day\', filtered_sessions.created_at), usage.currency'
            .' ORDER BY bucket ASC, usage.currency ASC'
            ." LIMIT {$rowLimit}";

        return new OperationalQuery($sql, $bindings, ['bucket', 'total_cost', 'currency', 'sessions'], $rowLimit);
    }

    /** @return array{string, list<mixed>} */
    private function cohort(DashboardFilters $filters): array
    {
        $conditions = ['sessions.created_at >= ?', 'sessions.created_at < ?'];
        $bindings = [$filters->from->toDateTimeString(), $filters->until->toDateTimeString()];
        if ($filters->organizationId !== null) {
            $conditions[] = 'sessions.organization_id = ?';
            $bindings[] = $filters->organizationId;
        }
        if ($filters->projectId !== null) {
            $conditions[] = 'sessions.project_id = ?';
            $bindings[] = $filters->projectId;
        }
        if ($filters->status !== null) {
            $conditions[] = 'sessions.status = ?';
            $bindings[] = $filters->status;
        }
        if ($filters->documentType !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM estimate_generation_documents AS documents WHERE documents.session_id = sessions.id AND documents.mime_type = ?)';
            $bindings[] = $filters->documentType;
        }
        if ($filters->mode !== null) {
            $conditions[] = "sessions.input_payload->>'generation_mode' = ?";
            $bindings[] = $filters->mode;
        }

        $usageConditions = ['usage_filter.session_id = sessions.id'];
        foreach (['provider' => 'provider', 'model' => 'requested_model', 'stage' => 'stage'] as $property => $column) {
            $value = $filters->{$property};
            if ($value !== null) {
                $usageConditions[] = "usage_filter.{$column} = ?";
                $bindings[] = $value;
            }
        }
        if (count($usageConditions) > 1) {
            $conditions[] = 'EXISTS (SELECT 1 FROM estimate_generation_ai_usage AS usage_filter WHERE '.implode(' AND ', $usageConditions).')';
        }

        $sql = <<<'SQL'
WITH filtered_sessions AS MATERIALIZED (
    SELECT sessions.id, sessions.status, sessions.applied_at, sessions.state_changed_at,
           sessions.created_at, sessions.updated_at
    FROM estimate_generation_sessions AS sessions
    WHERE
SQL;
        $sql .= implode(' AND ', $conditions)."\n)\n";

        return [$sql, $bindings];
    }
}
