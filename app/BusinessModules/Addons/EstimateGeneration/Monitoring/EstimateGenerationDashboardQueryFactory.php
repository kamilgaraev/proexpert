<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

final class EstimateGenerationDashboardQueryFactory
{
    public function sessionMetrics(DashboardFilters $filters): OperationalQuery
    {
        [$where, $bindings] = $this->sessionWhere($filters);
        $columns = [
            'sessions_total', 'successful_sessions', 'applied_sessions', 'review_sessions',
            'documents_total', 'average_duration_ms', 'p95_duration_ms',
        ];
        $sql = <<<'SQL'
WITH filtered_sessions AS MATERIALIZED (
    SELECT sessions.id, sessions.status, sessions.applied_at, sessions.state_changed_at,
           sessions.created_at, sessions.updated_at
    FROM estimate_generation_sessions AS sessions
SQL;
        $sql .= $where;
        $sql .= <<<'SQL'

), session_metrics AS (
    SELECT COUNT(*)::bigint AS sessions_total,
           COUNT(*) FILTER (WHERE status IN ('estimate_review_required', 'ready_to_apply', 'applied') OR applied_at IS NOT NULL)::bigint AS successful_sessions,
           COUNT(*) FILTER (WHERE applied_at IS NOT NULL)::bigint AS applied_sessions,
           COUNT(*) FILTER (WHERE status IN ('input_review_required', 'estimate_review_required'))::bigint AS review_sessions,
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

        return new OperationalQuery($sql, $bindings, $columns, 1);
    }

    public function usageMetrics(DashboardFilters $filters): OperationalQuery
    {
        [$where, $bindings] = $this->usageWhere($filters);
        $sql = <<<'SQL'
SELECT CASE WHEN COUNT(DISTINCT usage.currency) FILTER (WHERE usage.pricing_status = 'available') = 1
            THEN SUM(usage.cost_amount) FILTER (WHERE usage.pricing_status = 'available') END::numeric(20,8) AS total_cost,
       CASE WHEN COUNT(DISTINCT usage.currency) FILTER (WHERE usage.pricing_status = 'available') = 1
            THEN MIN(usage.currency) FILTER (WHERE usage.pricing_status = 'available') END AS currency
FROM estimate_generation_ai_usage AS usage
JOIN estimate_generation_sessions AS sessions ON sessions.id = usage.session_id
SQL;

        return new OperationalQuery($sql.$where, $bindings, ['total_cost', 'currency'], 1);
    }

    public function costTrend(DashboardFilters $filters): OperationalQuery
    {
        [$where, $bindings] = $this->usageWhere($filters);
        $sql = <<<'SQL'
SELECT DATE_TRUNC('day', usage.created_at) AS bucket,
       COALESCE(SUM(usage.cost_amount) FILTER (WHERE usage.pricing_status = 'available'), 0)::numeric(20,8) AS total_cost,
       usage.currency,
       COUNT(DISTINCT usage.session_id)::bigint AS sessions
FROM estimate_generation_ai_usage AS usage
JOIN estimate_generation_sessions AS sessions ON sessions.id = usage.session_id
SQL;
        $sql .= $where." AND usage.pricing_status = 'available' AND usage.currency IS NOT NULL"
            ." GROUP BY DATE_TRUNC('day', usage.created_at), usage.currency ORDER BY bucket ASC, usage.currency ASC LIMIT 282";

        return new OperationalQuery($sql, $bindings, ['bucket', 'total_cost', 'currency', 'sessions'], 282);
    }

    public function queueHealth(DashboardFilters $filters): OperationalQuery
    {
        [$tenantWhere, $bindings] = $this->tenantWhere($filters, 'checkpoints');
        $conditions = [
            'checkpoints.created_at >= ?',
            'checkpoints.created_at < ?',
            ...$tenantWhere,
        ];
        array_unshift($bindings, $filters->from->toDateTimeString(), $filters->until->toDateTimeString());
        if ($filters->stage !== null) {
            $conditions[] = 'checkpoints.stage = ?';
            $bindings[] = $filters->stage;
        }
        $sql = <<<'SQL'
SELECT COUNT(*) FILTER (WHERE checkpoints.status = 'running')::bigint AS running_jobs,
       COUNT(*) FILTER (WHERE checkpoints.status = 'running' AND checkpoints.lease_expires_at < CURRENT_TIMESTAMP)::bigint AS stale_jobs,
       COALESCE(MAX(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - checkpoints.created_at))) FILTER (WHERE checkpoints.status = 'running'), 0)::bigint AS oldest_queue_age_seconds
FROM estimate_generation_pipeline_checkpoints AS checkpoints
WHERE
SQL;

        return new OperationalQuery($sql.' '.implode(' AND ', $conditions), $bindings, [
            'running_jobs', 'stale_jobs', 'oldest_queue_age_seconds',
        ], 1);
    }

    /** @return array{string, list<mixed>} */
    private function sessionWhere(DashboardFilters $filters): array
    {
        $conditions = ['sessions.created_at >= ?', 'sessions.created_at < ?'];
        $bindings = [$filters->from->toDateTimeString(), $filters->until->toDateTimeString()];
        [$tenantWhere, $tenantBindings] = $this->tenantWhere($filters, 'sessions');
        $conditions = [...$conditions, ...$tenantWhere];
        $bindings = [...$bindings, ...$tenantBindings];
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

        return [' WHERE '.implode(' AND ', $conditions), $bindings];
    }

    /** @return array{string, list<mixed>} */
    private function usageWhere(DashboardFilters $filters): array
    {
        $conditions = ['usage.created_at >= ?', 'usage.created_at < ?'];
        $bindings = [$filters->from->toDateTimeString(), $filters->until->toDateTimeString()];
        [$tenantWhere, $tenantBindings] = $this->tenantWhere($filters, 'usage');
        $conditions = [...$conditions, ...$tenantWhere];
        $bindings = [...$bindings, ...$tenantBindings];
        foreach (['provider' => 'provider', 'model' => 'requested_model', 'stage' => 'stage'] as $property => $column) {
            $value = $filters->{$property};
            if ($value !== null) {
                $conditions[] = "usage.{$column} = ?";
                $bindings[] = $value;
            }
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

        return [' WHERE '.implode(' AND ', $conditions), $bindings];
    }

    /** @return array{list<string>, list<mixed>} */
    private function tenantWhere(DashboardFilters $filters, string $alias): array
    {
        $conditions = [];
        $bindings = [];
        if ($filters->organizationId !== null) {
            $conditions[] = "{$alias}.organization_id = ?";
            $bindings[] = $filters->organizationId;
        }
        if ($filters->projectId !== null) {
            $conditions[] = "{$alias}.project_id = ?";
            $bindings[] = $filters->projectId;
        }

        return [$conditions, $bindings];
    }
}
