<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use stdClass;

use function trans_message;

final class BuildSessionOperationalSnapshot
{
    public const QUERY_BUDGET = 11;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly BuildSessionSnapshot $workflowSnapshot,
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
                ->selectRaw("md5(COALESCE(problem_flags::text, '')) AS problem_revision");
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
            ->selectRaw("SUM(CASE WHEN status IN ('failed','needs_review') OR quality_level IN ('low','unusable') OR (status NOT IN ('uploaded','queued','processing','ignored') AND (COALESCE(facts_summary->'document_understanding'->>'role_for_estimation', '') = '' OR facts_summary->'document_understanding'->>'role_for_estimation' = 'needs_review' OR facts_summary->'document_understanding'->'extracted_capabilities'->>'requires_manual_review' = 'true' OR CASE WHEN jsonb_typeof(facts_summary->'conflicts') = 'array' THEN jsonb_array_length(facts_summary->'conflicts') ELSE 0 END > 0)) THEN 1 ELSE 0 END) AS action_required")
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
            ->selectRaw('COALESCE(SUM(COALESCE(input_tokens, 0) + COALESCE(cached_input_tokens, 0) + COALESCE(output_tokens, 0) + COALESCE(reasoning_tokens, 0)), 0) AS tokens')
            ->selectRaw('CASE WHEN COUNT(cost_amount) > 0 THEN SUM(cost_amount)::numeric(18,8)::text ELSE NULL END AS cost_amount')
            ->selectRaw('SUM(CASE WHEN cost_amount IS NULL THEN 1 ELSE 0 END) AS unknown_cost_attempts')
            ->selectRaw('MIN(currency) AS min_currency, MAX(currency) AS max_currency')
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
            ->selectRaw("SUM(CASE WHEN items.normative_status IN ('requires_review','not_found') THEN 1 ELSE 0 END) AS normative_review"));
    }

    /** @return array<string, mixed> */
    private function sourceWatermarks(Connection $connection, int $organizationId, int $projectId, int $sessionId): array
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_max_id,
                (SELECT COUNT(*) FROM estimate_generation_document_facts WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS facts_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_document_facts WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS facts_max_id,
                (SELECT COUNT(*) FROM estimate_generation_drawing_elements WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS drawings_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_drawing_elements WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS drawings_max_id,
                (SELECT COUNT(*) FROM estimate_generation_quantity_takeoffs WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS quantities_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_quantity_takeoffs WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS quantities_max_id,
                (SELECT COUNT(*) FROM estimate_generation_scope_inferences WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS scopes_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_scope_inferences WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS scopes_max_id,
                (SELECT COUNT(*) FROM estimate_generation_evidence_edges WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS edges_count,
                (SELECT COALESCE(MAX(id), 0) FROM estimate_generation_evidence_edges WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS edges_max_id,
                (SELECT COUNT(*) FROM estimate_generation_feedback f JOIN estimate_generation_sessions s ON s.id = f.session_id WHERE s.organization_id = ? AND s.project_id = ? AND f.session_id = ?) AS feedback_count,
                (SELECT COALESCE(MAX(f.id), 0) FROM estimate_generation_feedback f JOIN estimate_generation_sessions s ON s.id = f.session_id WHERE s.organization_id = ? AND s.project_id = ? AND f.session_id = ?) AS feedback_max_id,
                (SELECT COUNT(*) FROM estimate_generation_audit_events a JOIN estimate_generation_sessions s ON s.id = a.session_id WHERE s.organization_id = ? AND s.project_id = ? AND a.session_id = ?) AS audit_count,
                (SELECT COALESCE(MAX(a.id), 0) FROM estimate_generation_audit_events a JOIN estimate_generation_sessions s ON s.id = a.session_id WHERE s.organization_id = ? AND s.project_id = ? AND a.session_id = ?) AS audit_max_id
            SQL, array_merge(...array_fill(0, 16, [$organizationId, $projectId, $sessionId])));

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

        $blockers = [];
        if ($this->number($documents, 'total') === 0) {
            $blockers[] = $this->issue('no_documents', 'estimate_generation.readiness_blocker_no_documents');
        }
        if ($this->number($documents, 'pending') > 0) {
            $blockers[] = $this->issue('documents_pending', 'estimate_generation.readiness_blocker_documents_pending');
        }
        if ($this->number($documents, 'action_required') > 0) {
            $blockers[] = $this->issue('documents_require_review', 'estimate_generation.readiness_blocker_documents_require_review');
        }
        if ($this->number($failures, 'user_action_required') + $this->number($failures, 'terminal') > 0) {
            $blockers[] = $this->issue('generation_failures', 'estimate_generation.readiness_next_review_session');
        }
        if ($this->number($estimate, 'review_items') + $this->number($estimate, 'normative_review') > 0) {
            $blockers[] = $this->issue('review_items_require_action', 'estimate_generation.readiness_blocker_review_items_require_action');
        }

        $warnings = [];
        if ($this->number($evidence, 'low_confidence') > 0) {
            $warnings[] = $this->issue('low_confidence_evidence', 'estimate_generation.readiness_warning_low_document_understanding');
        }
        $canGenerate = $this->number($documents, 'ready') > 0
            && $this->number($documents, 'pending') === 0
            && $this->number($documents, 'action_required') === 0;
        $status = EstimateGenerationStatus::from((string) $session['status']);
        $canApply = $status === EstimateGenerationStatus::ReadyToApply && $blockers === [];
        $base = $this->workflowSnapshot->handle(
            $model,
            $permissions,
            ['blockers' => $blockers, 'warnings' => $warnings, 'can_generate' => $canGenerate, 'can_apply' => $canApply],
            $this->documentSummary($documents, $sources),
        );
        $revisionSources = compact('session', 'documents', 'checkpoint', 'checkpoints', 'units', 'evidence', 'usage', 'failures', 'finalization', 'estimate', 'sources');
        $operationalVersion = OperationalSnapshotRevision::fromSources($revisionSources);
        $currency = ($usage['min_currency'] ?? null) === ($usage['max_currency'] ?? null) ? ($usage['min_currency'] ?? null) : 'MIXED';

        return new SessionSnapshotData(
            id: $base->id,
            status: $base->status,
            processingStage: $base->processingStage,
            processingProgress: $base->processingProgress,
            stateVersion: $base->stateVersion,
            availableActions: $base->availableActions,
            blockingIssues: $blockers,
            warnings: $warnings,
            nextAction: $base->nextAction,
            readinessEvaluated: true,
            documentsSummary: $this->documentSummary($documents, $sources),
            estimateSummary: [
                'packages' => $this->number($estimate, 'packages'),
                'items' => $this->number($estimate, 'items'),
                'total_cost' => (string) ($estimate['total_cost'] ?? '0.00000000'),
                'currency' => 'RUB',
            ],
            reviewSummary: [
                'items' => $this->number($estimate, 'review_items'),
                'normatives' => $this->number($estimate, 'normative_review'),
                'blocking' => count($blockers),
            ],
            appliedEstimateId: $base->appliedEstimateId,
            updatedAt: $base->updatedAt,
            projectId: (int) $session['project_id'],
            operationalVersion: $operationalVersion,
            canGenerate: $canGenerate,
            canApply: $canApply,
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
                'status' => $blockers === [] ? 'ready' : 'review_required',
                'warnings' => count($warnings),
            ],
            usageSummary: [
                'attempts' => $this->number($usage, 'attempts'),
                'tokens' => $this->number($usage, 'tokens'),
                'cost_amount' => is_string($usage['cost_amount'] ?? null) ? $usage['cost_amount'] : null,
                'cost_known' => is_string($usage['cost_amount'] ?? null),
                'unknown_cost_attempts' => $this->number($usage, 'unknown_cost_attempts'),
                'currency' => $currency,
                'failed_attempts' => $this->number($usage, 'failed'),
            ],
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

    /** @return array{code: string, message_key: string, message: string} */
    private function issue(string $code, string $messageKey): array
    {
        return ['code' => $code, 'message_key' => $messageKey, 'message' => trans_message($messageKey)];
    }
}
