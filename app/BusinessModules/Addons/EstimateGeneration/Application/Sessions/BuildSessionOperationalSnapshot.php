<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\OperationalUsageSummary;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DocumentReadinessClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\OperationalReadinessInputFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use stdClass;

final class BuildSessionOperationalSnapshot implements SessionOperationalSnapshotBuilder
{
    public const QUERY_BUDGET = 11;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly BuildSessionSnapshot $workflowSnapshot,
        private readonly EstimatorReadinessEvaluator $readinessEvaluator,
        private readonly OperationalReadinessInputFactory $readinessInputFactory,
        private readonly DocumentReadinessClassifier $documentClassifier,
    ) {}

    /** @param list<string> $permissions */
    public function handle(EstimateGenerationSession $boundSession, array $permissions): SessionSnapshotData
    {
        $organizationId = (int) $boundSession->organization_id;
        $projectId = (int) $boundSession->project_id;
        $sessionId = (int) $boundSession->getKey();
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $organizationId, $projectId, $sessionId, $permissions): SessionSnapshotData {
            $this->beginConsistentRead($connection);
            $session = $this->session($connection, $organizationId, $projectId, $sessionId);
            $documents = $this->documents($connection, $organizationId, $projectId, $sessionId);
            $checkpoint = $this->currentCheckpoint($connection, $organizationId, $projectId, $sessionId);
            $checkpoints = $this->checkpoints($connection, $organizationId, $projectId, $sessionId);
            $units = $this->units($connection, $organizationId, $projectId, $sessionId);
            $evidence = $this->evidence($connection, $organizationId, $projectId, $sessionId);
            $usage = $this->usage($connection, $organizationId, $projectId, $sessionId);
            $failures = $this->failures($connection, $organizationId, $projectId, $sessionId);
            $finalization = $this->finalization($connection, $organizationId, $projectId, $sessionId);
            $estimate = $this->estimate($connection, $organizationId, $projectId, $sessionId);
            $sources = $this->sourceWatermarks($connection, $organizationId, $projectId, $sessionId);

            return $this->assemble(
                $session,
                $permissions,
                $documents,
                $checkpoint,
                $checkpoints,
                $units,
                $evidence,
                $usage,
                $failures,
                $finalization,
                $estimate,
                $sources,
            );
        }, 1);
    }

    private function beginConsistentRead(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
    }

    /** @return array<string, mixed> */
    private function session(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        $query = $connection->table('estimate_generation_sessions')
            ->where('id', $sessionId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->select([
                'id', 'organization_id', 'project_id', 'status', 'processing_stage', 'processing_progress',
                'state_version', 'applied_estimate_id', 'updated_at',
            ]);

        if ($connection->getDriverName() === 'pgsql') {
            $query->selectRaw("md5(COALESCE(input_payload::text, '')) AS input_revision")
                ->selectRaw("md5(COALESCE(analysis_payload::text, '')) AS analysis_revision")
                ->selectRaw("md5(COALESCE(draft_payload::text, '')) AS draft_revision")
                ->selectRaw("md5(COALESCE(problem_flags::text, '')) AS problem_revision")
                ->selectRaw("jsonb_array_length(CASE WHEN jsonb_typeof(draft_payload->'local_estimates') = 'array' THEN draft_payload->'local_estimates' ELSE '[]'::jsonb END) > 0 AS has_draft")
                ->selectRaw("COALESCE(draft_payload #>> '{quality_summary,status}', '') AS quality_status")
                ->selectRaw("COALESCE(draft_payload #>> '{quality_summary,level}', '') AS quality_level")
                ->selectRaw($this->jsonInteger('quality_total_work_items', '{quality_summary,total_work_items}'))
                ->selectRaw($this->jsonInteger('quality_priced_work_items', '{quality_summary,priced_work_items}'))
                ->selectRaw($this->jsonInteger('quality_operation_work_items', '{quality_summary,operation_work_items}'))
                ->selectRaw($this->jsonInteger('quality_quantity_review_work_items', '{quality_summary,quantity_review_work_items}'))
                ->selectRaw($this->jsonInteger('quality_not_calculated_work_items', '{quality_summary,not_calculated_work_items}'))
                ->selectRaw($this->jsonInteger('quality_safe_norm_required_work_items', '{quality_summary,safe_norm_required_work_items}'))
                ->selectRaw($this->jsonInteger('quality_duplicate_work_items', '{quality_summary,duplicate_work_items}'))
                ->selectRaw($this->jsonInteger('quality_normative_requires_review', '{quality_summary,normative_items,requires_review}'))
                ->selectRaw($this->jsonInteger('review_total', '{quality_summary,review_items,total}'))
                ->selectRaw($this->jsonInteger('review_blocking', '{quality_summary,review_items,blocking}'))
                ->selectRaw($this->jsonInteger('review_warning', '{quality_summary,review_items,warning}'))
                ->selectRaw($this->jsonInteger('review_optional', '{quality_summary,review_items,optional}'))
                ->selectRaw($this->jsonInteger('review_classifier_version', '{quality_summary,review_items,classifier_version}'))
                ->selectRaw("COALESCE(draft_payload #>> '{quality_summary,content_version}', '') AS review_content_version")
                ->selectRaw("COALESCE(draft_payload #>> '{quality_summary,review_items,source_version}', '') AS review_source_version")
                ->selectRaw("jsonb_array_length(CASE WHEN jsonb_typeof(COALESCE(draft_payload->'problem_flags', problem_flags)) = 'array' THEN COALESCE(draft_payload->'problem_flags', problem_flags) ELSE '[]'::jsonb END) AS problem_flags_count")
                ->selectRaw("jsonb_array_length(jsonb_path_query_array(COALESCE(draft_payload, '{}'::jsonb), '$.local_estimates[*].sections[*].work_items[*] ? (@.item_type == \"priced_work\" && @.pricing_status == \"calculated\" && @.total_cost <= 0)')) AS quality_zero_total_calculated_work_items");
        }

        $row = $query->first();
        if (! $row instanceof stdClass) {
            throw (new ModelNotFoundException)->setModel(EstimateGenerationSession::class, [$sessionId]);
        }

        return $this->row($row);
    }

    /** @return array<string, mixed> */
    private function documents(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_documents')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->selectRaw('COUNT(*) AS total, MAX(id) AS max_id, MAX(updated_at) AS max_updated_at')
            ->selectRaw("SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready")
            ->selectRaw("SUM(CASE WHEN status IN ('uploaded','queued','processing') THEN 1 ELSE 0 END) AS pending")
            ->selectRaw('SUM(CASE WHEN '.$this->documentClassifier->actionRequiredSql().' THEN 1 ELSE 0 END) AS action_required')
            ->selectRaw("SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) AS ignored")
            ->selectRaw('COALESCE(SUM(page_count), 0) AS pages, COALESCE(SUM(processed_page_count), 0) AS processed_pages')
            ->selectRaw('COALESCE(SUM(source_version), 0) AS source_versions, COALESCE(SUM(ocr_attempts), 0) AS ocr_attempts'));
    }

    /** @return array<string, mixed> */
    private function currentCheckpoint(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        $row = $connection->table('estimate_generation_pipeline_checkpoints')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->select(['stage', 'status', 'attempt_count', 'lease_expires_at', 'started_at', 'completed_at', 'updated_at'])
            ->selectRaw('CASE WHEN lease_expires_at IS NULL THEN FALSE ELSE lease_expires_at < CURRENT_TIMESTAMP END AS lease_expired')
            ->orderByDesc('updated_at')->orderByDesc('id')->first();

        return $row instanceof stdClass ? $this->row($row) : [];
    }

    /** @return array<string, mixed> */
    private function checkpoints(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_pipeline_checkpoints')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->selectRaw('COUNT(*) AS total, MAX(id) AS max_id, MAX(updated_at) AS max_updated_at, COALESCE(SUM(attempt_count), 0) AS attempts')
            ->selectRaw("SUM(CASE WHEN status IN ('pending','retry_scheduled') THEN 1 ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN lease_expires_at < CURRENT_TIMESTAMP AND status = 'running' THEN 1 ELSE 0 END) AS expired"));
    }

    /** @return array<string, mixed> */
    private function units(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_processing_units')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->selectRaw('COUNT(*) AS total, MAX(id) AS max_id, MAX(updated_at) AS max_updated_at, COALESCE(SUM(attempt_count), 0) AS attempts, COALESCE(SUM(output_count), 0) AS outputs')
            ->selectRaw("SUM(CASE WHEN status IN ('pending','queued') THEN 1 ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS running")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN lease_expires_at < CURRENT_TIMESTAMP AND status = 'processing' THEN 1 ELSE 0 END) AS expired"));
    }

    /** @return array<string, mixed> */
    private function evidence(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_evidence')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->selectRaw('COUNT(*) AS total, MAX(id) AS max_id, MAX(updated_at) AS max_updated_at, COALESCE(MAX(invalidation_version), 0) AS invalidation_version')
            ->selectRaw('SUM(CASE WHEN invalidated_at IS NULL THEN 1 ELSE 0 END) AS active')
            ->selectRaw('SUM(CASE WHEN invalidated_at IS NOT NULL THEN 1 ELSE 0 END) AS invalidated')
            ->selectRaw('SUM(CASE WHEN invalidated_at IS NULL AND confidence < 0.6 THEN 1 ELSE 0 END) AS low_confidence'));
    }

    /** @return array<string, mixed> */
    private function usage(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_ai_usage')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->selectRaw('COUNT(*) AS attempts, MAX(created_at) AS max_created_at')
            ->selectRaw('COALESCE(SUM(COALESCE(input_tokens, 0)), 0) AS input_tokens, COALESCE(SUM(COALESCE(cached_input_tokens, 0)), 0) AS cached_input_tokens')
            ->selectRaw('COALESCE(SUM(COALESCE(output_tokens, 0)), 0) AS output_tokens, COALESCE(SUM(COALESCE(reasoning_tokens, 0)), 0) AS reasoning_tokens')
            ->selectRaw('COUNT(cost_amount) AS known_cost_attempts')
            ->selectRaw('SUM(CASE WHEN cost_amount IS NULL THEN 1 ELSE 0 END) AS unknown_cost_attempts')
            ->selectRaw('CASE WHEN COUNT(cost_amount) > 0 THEN SUM(cost_amount)::numeric(18,8)::text ELSE NULL END AS known_cost_subtotal')
            ->selectRaw('COUNT(DISTINCT currency) FILTER (WHERE cost_amount IS NOT NULL) AS currency_count')
            ->selectRaw('MIN(currency) FILTER (WHERE cost_amount IS NOT NULL) AS min_currency, MAX(currency) FILTER (WHERE cost_amount IS NOT NULL) AS max_currency')
            ->selectRaw("SUM(CASE WHEN status IN ('http_failed','transport_failed','usage_unavailable') THEN 1 ELSE 0 END) AS failed"));
    }

    /** @return array<string, mixed> */
    private function failures(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_failures')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->selectRaw('COUNT(*) AS total, MAX(latest_occurrence_sequence) AS max_sequence, MAX(last_seen_at) AS max_last_seen_at, COALESCE(SUM(occurrence_count), 0) AS occurrences')
            ->selectRaw('SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS active')
            ->selectRaw("SUM(CASE WHEN resolved_at IS NULL AND category = 'recoverable' THEN 1 ELSE 0 END) AS recoverable")
            ->selectRaw("SUM(CASE WHEN resolved_at IS NULL AND category = 'user_action_required' THEN 1 ELSE 0 END) AS user_action_required")
            ->selectRaw("SUM(CASE WHEN resolved_at IS NULL AND category = 'terminal' THEN 1 ELSE 0 END) AS terminal"));
    }

    /** @return array<string, mixed> */
    private function finalization(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_total,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_max_id,
                (SELECT COALESCE(SUM(attempt_count), 0) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_attempts,
                (SELECT COUNT(*) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ? AND status <> 'delivered') AS outbox_pending,
                (SELECT MAX(updated_at) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_finalization_deliveries WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS deliveries_total,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_finalization_deliveries WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS deliveries_max_id,
                (SELECT COUNT(*) FROM estimate_generation_finalization_deliveries WHERE organization_id = ? AND project_id = ? AND session_id = ? AND status = 'pending') AS deliveries_pending,
                (SELECT MAX(updated_at) FROM estimate_generation_finalization_deliveries WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS deliveries_max_updated_at
            SQL, array_merge(...array_fill(0, 9, [$organizationId, $projectId, $sessionId])));

        return $row instanceof stdClass ? $this->row($row) : [];
    }

    /** @return array<string, mixed> */
    private function estimate(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->aggregate($connection->table('estimate_generation_packages AS packages')
            ->join('estimate_generation_sessions AS sessions', 'sessions.id', '=', 'packages.session_id')
            ->leftJoin('estimate_generation_package_items AS items', 'items.package_id', '=', 'packages.id')
            ->where('sessions.organization_id', $organizationId)
            ->where('sessions.project_id', $projectId)
            ->where('packages.session_id', $sessionId)
            ->selectRaw('COUNT(DISTINCT packages.id) AS packages, MAX(packages.id) AS max_package_id, MAX(packages.updated_at) AS max_package_updated_at')
            ->selectRaw('COUNT(items.id) AS items, MAX(items.id) AS max_item_id, MAX(items.updated_at) AS max_item_updated_at')
            ->selectRaw("COALESCE(SUM(CASE WHEN items.item_type NOT IN ('operation','resource_note','review_note') THEN items.total_cost ELSE 0 END), 0)::numeric(30,8)::text AS total_cost")
            ->selectRaw("SUM(CASE WHEN items.item_type = 'quantity_review' THEN 1 ELSE 0 END) AS review_items")
            ->selectRaw("SUM(CASE WHEN items.normative_status IN ('candidate','rejected','requires_review','not_found','unmatched','low_confidence') THEN 1 ELSE 0 END) AS normative_review")
        );
    }

    /** @return array<string, mixed> */
    private function sourceWatermarks(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_max_id,
                (SELECT MAX(updated_at) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_document_facts WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS facts_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_document_facts WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS facts_max_id,
                (SELECT MAX(updated_at) FROM estimate_generation_document_facts WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS facts_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_drawing_elements WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS drawings_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_drawing_elements WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS drawings_max_id,
                (SELECT MAX(updated_at) FROM estimate_generation_drawing_elements WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS drawings_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_quantity_takeoffs WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS quantities_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_quantity_takeoffs WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS quantities_max_id,
                (SELECT MAX(updated_at) FROM estimate_generation_quantity_takeoffs WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS quantities_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_scope_inferences WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS scopes_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_scope_inferences WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS scopes_max_id,
                (SELECT MAX(updated_at) FROM estimate_generation_scope_inferences WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS scopes_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_evidence_edges WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS edges_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_evidence_edges WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS edges_max_id,
                (SELECT MAX(created_at) FROM estimate_generation_evidence_edges WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS edges_max_created_at,
                (SELECT COUNT(*) FROM estimate_generation_feedback f JOIN estimate_generation_sessions s ON s.id = f.session_id WHERE s.organization_id = ? AND s.project_id = ? AND f.session_id = ?) AS feedback_count,
                (SELECT COALESCE(MAX(f.id), 0) FROM estimate_generation_feedback f JOIN estimate_generation_sessions s ON s.id = f.session_id WHERE s.organization_id = ? AND s.project_id = ? AND f.session_id = ?) AS feedback_max_id,
                (SELECT MAX(f.updated_at) FROM estimate_generation_feedback f JOIN estimate_generation_sessions s ON s.id = f.session_id WHERE s.organization_id = ? AND s.project_id = ? AND f.session_id = ?) AS feedback_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_audit_events a JOIN estimate_generation_sessions s ON s.id = a.session_id WHERE s.organization_id = ? AND s.project_id = ? AND a.session_id = ?) AS audit_count,
                (SELECT COALESCE(MAX(a.id), 0) FROM estimate_generation_audit_events a JOIN estimate_generation_sessions s ON s.id = a.session_id WHERE s.organization_id = ? AND s.project_id = ? AND a.session_id = ?) AS audit_max_id,
                (SELECT MAX(a.updated_at) FROM estimate_generation_audit_events a JOIN estimate_generation_sessions s ON s.id = a.session_id WHERE s.organization_id = ? AND s.project_id = ? AND a.session_id = ?) AS audit_max_updated_at,
                (SELECT COUNT(*) FROM estimate_generation_failure_events WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS failure_events_count,
                (SELECT COALESCE(MAX(sequence), 0) FROM estimate_generation_failure_events WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS failure_events_max_sequence
            SQL, array_merge(...array_fill(0, 25, [$organizationId, $projectId, $sessionId])));

        return $row instanceof stdClass ? $this->row($row) : [];
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  ...$parts
     */
    private function assemble(array $session, array $permissions, array ...$parts): SessionSnapshotData
    {
        [$documents, $checkpoint, $checkpoints, $units, $evidence, $usage, $failures, $finalization, $estimate, $sources] = $parts;
        $model = new EstimateGenerationSession;
        $model->forceFill([
            ...$session,
            'updated_at' => isset($session['updated_at']) ? CarbonImmutable::parse((string) $session['updated_at']) : null,
            'draft_payload' => ['quality_summary' => []],
        ]);
        $model->exists = true;

        $readiness = $this->readinessEvaluator->evaluate(
            $this->readinessInputFactory->fromAggregates($session, $documents, $estimate, $sources),
        );
        $base = $this->workflowSnapshot->handle(
            $model,
            $permissions,
            $readiness,
            $this->documentSummary($documents, $sources),
        );
        $revisionSources = compact('session', 'documents', 'checkpoint', 'checkpoints', 'units', 'evidence', 'usage', 'failures', 'finalization', 'estimate', 'sources');
        $operationalVersion = OperationalSnapshotRevision::fromSources($revisionSources);

        return new SessionSnapshotData(
            id: $base->id,
            status: $base->status,
            processingStage: $base->processingStage,
            processingProgress: $base->processingProgress,
            stateVersion: $base->stateVersion,
            availableActions: $base->availableActions,
            blockingIssues: $readiness['blockers'],
            warnings: $readiness['warnings'],
            nextAction: $base->nextAction ?? (string) ($readiness['next_action']['code'] ?? ''),
            readinessEvaluated: true,
            documentsSummary: $this->documentSummary($documents, $sources),
            estimateSummary: [
                'packages' => $this->number($estimate, 'packages'),
                'items' => $this->number($estimate, 'items'),
                'total_cost' => (string) ($estimate['total_cost'] ?? '0.00000000'),
                'currency' => 'RUB',
            ],
            reviewSummary: [
                'items' => (int) $readiness['metrics']['review_items_total'],
                'normatives' => (int) $readiness['metrics']['normative_requires_review'],
                'blocking' => (int) $readiness['metrics']['review_items_blocking'],
                'warning' => (int) $readiness['metrics']['review_items_warning'],
                'optional' => (int) $readiness['metrics']['review_items_optional'],
            ],
            appliedEstimateId: $base->appliedEstimateId,
            updatedAt: $base->updatedAt,
            projectId: (int) $session['project_id'],
            operationalVersion: $operationalVersion,
            canGenerate: (bool) $readiness['can_generate'],
            canApply: (bool) $readiness['can_apply'],
            currentCheckpoint: $this->checkpointSummary($checkpoint),
            queueSummary: [
                'pending' => $this->number($checkpoints, 'pending') + $this->number($units, 'pending') + $this->number($finalization, 'outbox_pending'),
                'running' => $this->number($checkpoints, 'running') + $this->number($units, 'running'),
            ],
            recoverySummary: [
                'recoverable' => $this->number($failures, 'recoverable'),
                'expired_claims' => $this->number($checkpoints, 'expired') + $this->number($units, 'expired'),
                'pending_deliveries' => $this->number($finalization, 'deliveries_pending'),
            ],
            evidenceSummary: [
                'active' => $this->number($evidence, 'active'),
                'invalidated' => $this->number($evidence, 'invalidated'),
                'low_confidence' => $this->number($evidence, 'low_confidence'),
            ],
            qualitySummary: [
                'status' => (string) $readiness['status'],
                'quality_status' => (string) ($session['quality_status'] ?? ''),
                'quality_level' => (string) ($session['quality_level'] ?? ''),
                'warnings' => count($readiness['warnings']),
            ],
            usageSummary: OperationalUsageSummary::fromAggregate($usage),
            failureSummary: [
                'active' => $this->number($failures, 'active'),
                'categories' => [
                    'recoverable' => $this->number($failures, 'recoverable'),
                    'user_action_required' => $this->number($failures, 'user_action_required'),
                    'terminal' => $this->number($failures, 'terminal'),
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function aggregate(\Illuminate\Database\Query\Builder $query): array
    {
        $row = $query->first();

        return $row instanceof stdClass ? $this->row($row) : [];
    }

    private function jsonInteger(string $alias, string $path): string
    {
        return "CASE WHEN jsonb_typeof(draft_payload #> '{$path}') = 'number' THEN GREATEST(0, (draft_payload #>> '{$path}')::bigint) ELSE 0 END AS {$alias}";
    }

    /** @return array<string, mixed> */
    private function row(stdClass $row): array
    {
        return get_object_vars($row);
    }

    /** @param array<string, mixed> $value */
    private function number(array $value, string $key): int
    {
        return max(0, (int) ($value[$key] ?? 0));
    }

    /** @return array<string, mixed> */
    private function documentSummary(array $documents, array $sources): array
    {
        return [
            'total' => $this->number($documents, 'total'),
            'ready' => $this->number($documents, 'ready'),
            'pending' => $this->number($documents, 'pending'),
            'action_required' => $this->number($documents, 'action_required'),
            'ignored' => $this->number($documents, 'ignored'),
            'pages' => $this->number($sources, 'pages_count'),
            'facts' => $this->number($sources, 'facts_count'),
            'drawing_elements' => $this->number($sources, 'drawings_count'),
            'quantity_takeoffs' => $this->number($sources, 'quantities_count'),
            'scope_inferences' => $this->number($sources, 'scopes_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function checkpointSummary(array $checkpoint): array
    {
        if ($checkpoint === []) {
            return [];
        }

        return [
            'stage' => (string) ($checkpoint['stage'] ?? ''),
            'status' => (string) ($checkpoint['status'] ?? ''),
            'attempt' => $this->number($checkpoint, 'attempt_count'),
            'lease_expires_at' => $this->timestamp($checkpoint['lease_expires_at'] ?? null),
            'lease_expired' => in_array($checkpoint['lease_expired'] ?? false, [true, 1, '1', 't', 'true'], true),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toIso8601String();
    }
}
