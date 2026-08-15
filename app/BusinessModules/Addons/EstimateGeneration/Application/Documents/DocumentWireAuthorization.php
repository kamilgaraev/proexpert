<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class DocumentWireAuthorization
{
    public function __construct(private Connection $database) {}

    public function denialReason(string $attemptId, DateTimeImmutable $now): ?string
    {
        $scope = $this->database->table('estimate_generation_vision_physical_attempts as attempts')
            ->join('estimate_generation_processing_units as units', 'units.id', '=', 'attempts.unit_id')
            ->where('attempts.attempt_id', $attemptId)
            ->first([
                'attempts.organization_id',
                'attempts.project_id',
                'attempts.session_id',
                'attempts.document_id',
                'attempts.processing_lineage_id',
                'units.source_version',
            ]);
        if ($scope === null) {
            return 'document_processing_stopped';
        }

        $document = $this->database->table('estimate_generation_documents')
            ->where('id', $scope->document_id)
            ->where('organization_id', $scope->organization_id)
            ->where('project_id', $scope->project_id)
            ->where('session_id', $scope->session_id)
            ->lockForUpdate()
            ->first();
        if ($document === null
            || ! hash_equals((string) $document->source_version, (string) $scope->source_version)) {
            return 'document_processing_stopped';
        }
        if ((string) $document->processing_control_status !== 'active') {
            return (string) $document->processing_control_status === 'paused'
                ? 'document_cost_limit_reached'
                : 'document_processing_stopped';
        }

        $meta = is_string($document->meta) ? json_decode($document->meta, true) : $document->meta;
        $lineage = is_array($meta) && is_string($meta['processing_attempt_id'] ?? null)
            ? $meta['processing_attempt_id']
            : null;
        if ($lineage !== null && ! hash_equals($lineage, (string) $scope->processing_lineage_id)) {
            return 'document_processing_stopped';
        }

        $limit = $document->processing_cost_limit === null
            ? (string) config('estimate-generation.generation.document_cost_limit_rub', '50.00')
            : (string) $document->processing_cost_limit;
        $usage = $this->database->selectOne(<<<'SQL'
SELECT COALESCE(SUM(usage.cost_amount) FILTER (
           WHERE usage.pricing_status = 'available' AND usage.currency = 'RUB'
       ), 0)::numeric(20,8) AS spent,
       COUNT(*) FILTER (
           WHERE usage.pricing_status <> 'available'
              OR usage.cost_amount IS NULL
              OR usage.currency IS DISTINCT FROM 'RUB'
       )::int AS unknown_count,
       (COALESCE(SUM(usage.cost_amount) FILTER (
           WHERE usage.pricing_status = 'available' AND usage.currency = 'RUB'
       ), 0) >= ?::numeric) AS limit_reached
FROM estimate_generation_vision_physical_attempts AS attempts
LEFT JOIN estimate_generation_ai_usage AS usage
  ON usage.attempt_id = attempts.attempt_id
WHERE attempts.organization_id = ?
  AND attempts.project_id = ?
  AND attempts.session_id = ?
  AND attempts.document_id = ?
  AND attempts.processing_lineage_id = ?
  AND attempts.state IN ('wire_started', 'response_received', 'completed', 'ambiguous')
SQL, [
            $limit,
            $scope->organization_id,
            $scope->project_id,
            $scope->session_id,
            $scope->document_id,
            $scope->processing_lineage_id,
        ]);
        $limitReached = filter_var($usage?->limit_reached ?? false, FILTER_VALIDATE_BOOL)
            || (int) ($usage?->unknown_count ?? 0) > 0;
        if (! $limitReached) {
            return null;
        }

        $this->database->table('estimate_generation_documents')
            ->where('id', $scope->document_id)
            ->update([
                'status' => 'needs_review',
                'processing_stage' => 'quality_check',
                'processing_control_status' => 'paused',
                'processing_control_source_version' => (string) $scope->source_version,
                'processing_control_attempt_id' => (string) $scope->processing_lineage_id,
                'processing_control_reason' => 'cost_limit_reached',
                'processing_control_at' => $now,
                'processing_cost_limit' => $limit,
                'updated_at' => $now,
            ]);

        return 'document_cost_limit_reached';
    }
}
