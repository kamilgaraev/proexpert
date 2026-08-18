<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class DocumentWireAuthorization
{
    public function __construct(private Connection $database) {}

    public function denialReason(string $attemptId, DateTimeImmutable $now, AiCost $costReservation): ?string
    {
        $scope = $this->database->table('estimate_generation_vision_physical_attempts as attempts')
            ->join('estimate_generation_processing_units as units', 'units.id', '=', 'attempts.unit_id')
            ->where('attempts.attempt_id', $attemptId)
            ->first([
                'attempts.organization_id', 'attempts.project_id', 'attempts.session_id',
                'attempts.document_id', 'attempts.processing_lineage_id', 'units.source_version',
            ]);
        if ($scope === null) {
            return 'document_processing_stopped';
        }

        $session = $this->database->table('estimate_generation_sessions')
            ->where('id', $scope->session_id)
            ->where('organization_id', $scope->organization_id)
            ->where('project_id', $scope->project_id)
            ->lockForUpdate()
            ->first(['analysis_payload']);
        if ($session === null) {
            return 'document_processing_stopped';
        }
        $document = $this->database->table('estimate_generation_documents')
            ->where('id', $scope->document_id)
            ->where('organization_id', $scope->organization_id)
            ->where('project_id', $scope->project_id)
            ->where('session_id', $scope->session_id)
            ->lockForUpdate()
            ->first();
        if ($document === null || ! hash_equals((string) $document->source_version, (string) $scope->source_version)) {
            return 'document_processing_stopped';
        }
        if ((string) $document->processing_control_status !== 'active') {
            if ((string) $document->processing_control_status !== 'paused') {
                return 'document_processing_stopped';
            }

            return (string) $document->processing_control_reason === 'session_cost_limit_reached'
                ? 'session_cost_limit_reached'
                : 'document_cost_limit_reached';
        }

        $meta = is_string($document->meta) ? json_decode($document->meta, true) : $document->meta;
        $meta = is_array($meta) ? $meta : [];
        $lineage = is_string($meta['processing_attempt_id'] ?? null) ? $meta['processing_attempt_id'] : null;
        if ($lineage !== null && ! hash_equals($lineage, (string) $scope->processing_lineage_id)) {
            return 'document_processing_stopped';
        }

        $limit = $document->processing_cost_limit === null
            ? (string) config('estimate-generation.generation.document_cost_limit_rub', '600.00')
            : (string) $document->processing_cost_limit;
        $analysis = is_string($session->analysis_payload)
            ? json_decode($session->analysis_payload, true)
            : $session->analysis_payload;
        $guard = is_array($analysis) && is_array($analysis['internal_cost_guard'] ?? null)
            ? $analysis['internal_cost_guard']
            : [];
        $sessionLimit = BigDecimal::of((string) config(
            'estimate-generation.generation.session_cost_limit_rub', '900.00',
        ))->plus(BigDecimal::of((string) config(
            'estimate-generation.generation.session_cost_confirmation_increment_rub', '450.00',
        ))->multipliedBy(max(0, (int) ($guard['confirmation_version'] ?? 0))));

        $reservationAvailable = $costReservation->pricingStatus === 'available'
            && $costReservation->currency === 'RUB'
            && is_string($costReservation->amount)
            && preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $costReservation->amount) === 1;
        $reservation = $reservationAvailable ? (string) $costReservation->amount : '0';
        if ($reservationAvailable) {
            $this->database->table('estimate_generation_vision_physical_attempts')
                ->where('organization_id', $scope->organization_id)
                ->where('project_id', $scope->project_id)
                ->where('session_id', $scope->session_id)
                ->whereIn('state', ['wire_started', 'response_received', 'completed', 'ambiguous'])
                ->whereNull('cost_reservation_amount')
                ->update([
                    'cost_reservation_amount' => $reservation,
                    'cost_reservation_currency' => 'RUB',
                    'updated_at' => $now,
                ]);
        }
        $documentExposure = $this->exposure(
            (int) $scope->organization_id, (int) $scope->project_id, (int) $scope->session_id,
            (int) $scope->document_id, $reservation,
        );
        $sessionExposure = $this->exposure(
            (int) $scope->organization_id, (int) $scope->project_id, (int) $scope->session_id,
            null, $reservation,
        );
        $documentProjected = BigDecimal::of($documentExposure['spent'])
            ->plus($documentExposure['reserved'])->plus($reservation);
        $sessionProjected = BigDecimal::of($sessionExposure['spent'])
            ->plus($sessionExposure['reserved'])->plus($reservation);
        $documentLimitReached = ! $reservationAvailable
            || $documentExposure['unknown'] > 0
            || $documentProjected->isGreaterThan(BigDecimal::of($limit));
        $sessionLimitReached = ! $reservationAvailable
            || $sessionExposure['unknown'] > 0
            || $sessionProjected->isGreaterThan($sessionLimit);
        if (! $documentLimitReached && ! $sessionLimitReached) {
            return null;
        }

        $reason = $sessionLimitReached ? 'session_cost_limit_reached' : 'cost_limit_reached';
        $this->database->table('estimate_generation_documents')
            ->where('id', $scope->document_id)
            ->update([
                'status' => 'needs_review',
                'processing_stage' => 'quality_check',
                'processing_control_status' => 'paused',
                'processing_control_source_version' => (string) $scope->source_version,
                'processing_control_attempt_id' => (string) $scope->processing_lineage_id,
                'processing_control_reason' => $reason,
                'processing_control_at' => $now,
                'processing_cost_limit' => $limit,
                'meta' => json_encode([
                    ...$meta,
                    'session_cost_guard_confirmation_version' => max(
                        0, (int) ($guard['confirmation_version'] ?? 0),
                    ),
                    'processing_cost_guard' => [
                        'version' => 2,
                        'document_projected_rub' => (string) $documentProjected,
                        'session_projected_rub' => (string) $sessionProjected,
                        'next_call_reservation_rub' => $reservationAvailable ? $reservation : null,
                    ],
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

        return $sessionLimitReached ? 'session_cost_limit_reached' : 'document_cost_limit_reached';
    }

    /** @return array{spent: string, reserved: string, unknown: int} */
    private function exposure(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?int $documentId,
        string $legacyReservation,
    ): array {
        $documentUsage = $documentId === null ? '' : ' AND usage.document_id = ?';
        $usageBindings = [$organizationId, $projectId, $sessionId];
        if ($documentId !== null) {
            $usageBindings[] = $documentId;
        }
        $usage = $this->database->selectOne(<<<SQL
SELECT COALESCE(SUM(usage.cost_amount) FILTER (
           WHERE usage.pricing_status = 'available' AND usage.currency = 'RUB'
       ), 0)::numeric(20,8) AS spent,
       COUNT(*) FILTER (
           WHERE (usage.pricing_status <> 'available'
              OR usage.cost_amount IS NULL
              OR usage.currency IS DISTINCT FROM 'RUB')
             AND NOT EXISTS (
                 SELECT 1 FROM estimate_generation_vision_physical_attempts attempts
                 WHERE attempts.attempt_id = usage.attempt_id
             )
       )::int AS unknown_count
FROM estimate_generation_ai_usage usage
WHERE usage.organization_id = ?
  AND usage.project_id = ?
  AND usage.session_id = ?{$documentUsage}
SQL, $usageBindings);

        $documentAttempts = $documentId === null ? '' : ' AND attempts.document_id = ?';
        $attemptBindings = [$legacyReservation, $organizationId, $projectId, $sessionId];
        if ($documentId !== null) {
            $attemptBindings[] = $documentId;
        }
        $attempts = $this->database->selectOne(<<<SQL
SELECT COALESCE(SUM(
           CASE
               WHEN attempts.cost_reservation_amount IS NOT NULL
                AND attempts.cost_reservation_currency = 'RUB'
               THEN attempts.cost_reservation_amount
               ELSE ?::numeric
           END
       ), 0)::numeric(20,8) AS reserved
FROM estimate_generation_vision_physical_attempts attempts
WHERE attempts.organization_id = ?
  AND attempts.project_id = ?
  AND attempts.session_id = ?{$documentAttempts}
  AND attempts.state IN ('wire_started', 'response_received', 'completed', 'ambiguous')
  AND (attempts.state = 'ambiguous' OR NOT EXISTS (
      SELECT 1 FROM estimate_generation_ai_usage usage
      WHERE usage.attempt_id = attempts.attempt_id
        AND usage.pricing_status = 'available'
        AND usage.currency = 'RUB'
        AND usage.cost_amount IS NOT NULL
  ))
SQL, $attemptBindings);

        return [
            'spent' => (string) ($usage?->spent ?? '0'),
            'reserved' => (string) ($attempts?->reserved ?? '0'),
            'unknown' => (int) ($usage?->unknown_count ?? 0),
        ];
    }
}
