<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;

final readonly class EloquentVisionPhysicalAttemptStore implements VisionPhysicalAttemptStore
{
    private const TABLE = 'estimate_generation_vision_physical_attempts';

    public function __construct(
        private Connection $database,
        private VisionPhysicalAttemptStateMachine $stateMachine = new VisionPhysicalAttemptStateMachine,
    ) {}

    public function claim(
        AiOperationContext $context,
        string $requestFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): VisionPhysicalAttemptSnapshot {
        $databaseNow = $this->databaseTime($now);
        $databaseLeaseExpiresAt = $this->databaseTime($leaseExpiresAt);
        $this->database->table(self::TABLE)->insertOrIgnore([
            'attempt_id' => $context->attemptId,
            'request_fingerprint' => $requestFingerprint,
            'logical_request_fingerprint' => $requestFingerprint,
            'processing_lineage_id' => $context->processingLineageId,
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'session_id' => $context->sessionId,
            'document_id' => $context->documentId,
            'page_id' => $context->pageId,
            'unit_id' => $context->unitId,
            'state' => 'pre_wire',
            'owner_token' => $ownerToken,
            'lease_expires_at' => $databaseLeaseExpiresAt,
            'usage_recorded' => false,
            'created_at' => $databaseNow,
            'updated_at' => $databaseNow,
        ]);

        return $this->database->transaction(function () use (
            $context,
            $requestFingerprint,
            $ownerToken,
            $now,
            $leaseExpiresAt,
        ): VisionPhysicalAttemptSnapshot {
            $query = $this->database->table(self::TABLE)->where('attempt_id', $context->attemptId);
            $row = $query->lockForUpdate()->first();
            if ($row === null || ! hash_equals((string) $row->request_fingerprint, $requestFingerprint)) {
                throw new VisionPhysicalAttemptCollision;
            }
            $snapshot = $this->snapshot($row);
            $decision = $this->stateMachine->claim($snapshot, $ownerToken, $now);
            if ($decision->action === 'takeover') {
                $query->update([
                    'owner_token' => $ownerToken,
                    'lease_expires_at' => $this->databaseTime($leaseExpiresAt),
                    'updated_at' => $this->databaseTime($now),
                ]);

                return new VisionPhysicalAttemptSnapshot(
                    true,
                    'pre_wire',
                    ownerToken: $ownerToken,
                    leaseExpiresAt: $leaseExpiresAt,
                );
            }
            if ($decision->action === 'ambiguous' && ! in_array($snapshot->state, ['ambiguous', 'response_received', 'completed'], true)) {
                $query->update([
                    'state' => 'ambiguous',
                    'owner_token' => null,
                    'lease_expires_at' => null,
                    'ambiguous_at' => $this->databaseTime($now),
                    'terminal_reason' => $snapshot->state === 'reserved'
                        ? 'legacy_reserved_outcome_unknown'
                        : 'wire_outcome_unknown_after_lease_expiry',
                    'status' => 'ambiguous',
                    'duration_ms' => 0,
                    'price_snapshot' => '{}',
                    'updated_at' => $this->databaseTime($now),
                ]);

                return new VisionPhysicalAttemptSnapshot(
                    false,
                    'ambiguous',
                    status: 'ambiguous',
                    durationMs: 0,
                    priceSnapshot: [],
                    terminalReason: 'wire_outcome_unknown',
                );
            }

            return $snapshot;
        }, 3);
    }

    public function markWireStarted(
        string $attemptId,
        string $requestFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): void {
        $databaseNow = $this->databaseTime($now);
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'pre_wire')
            ->where('owner_token', $ownerToken)
            ->where('lease_expires_at', '>', $databaseNow)
            ->update([
                'state' => 'wire_started',
                'wire_started_at' => $databaseNow,
                'lease_expires_at' => $this->databaseTime($leaseExpiresAt),
                'updated_at' => $databaseNow,
            ]);
        if ($updated !== 1) {
            throw new UsageInvariantViolation('Vision physical attempt wire claim lost.');
        }
    }

    public function storeResponse(
        string $attemptId,
        string $requestFingerprint,
        string $ownerToken,
        array $responsePayload,
        string $status,
        ?int $httpCode,
        int $durationMs,
        ?string $reportedModel,
        array $priceSnapshot,
    ): void {
        $databaseNow = $this->databaseTime(new DateTimeImmutable);
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'wire_started')
            ->where('owner_token', $ownerToken)
            ->update([
                'state' => 'response_received',
                'owner_token' => null,
                'lease_expires_at' => null,
                'response_payload' => json_encode($responsePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'status' => $status,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'reported_model' => $reportedModel,
                'price_snapshot' => json_encode($priceSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'response_received_at' => $databaseNow,
                'updated_at' => $databaseNow,
            ]);
        if ($updated !== 1) {
            $row = $this->database->table(self::TABLE)->where('attempt_id', $attemptId)->first();
            if ($row === null || ! hash_equals((string) $row->request_fingerprint, $requestFingerprint)
                || ! in_array((string) $row->state, ['response_received', 'completed'], true)
                || ! hash_equals($this->payloadFingerprint($this->decode((string) $row->response_payload)), $this->payloadFingerprint($responsePayload))) {
                throw new UsageInvariantViolation('Vision physical response collision.');
            }
        }
    }

    public function markAmbiguous(
        string $attemptId,
        string $requestFingerprint,
        string $ownerToken,
        string $reason,
        DateTimeImmutable $now,
        int $durationMs,
        ?int $httpCode,
        ?string $reportedModel,
        array $priceSnapshot,
    ): void {
        $databaseNow = $this->databaseTime($now);
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'wire_started')
            ->where('owner_token', $ownerToken)
            ->update([
                'state' => 'ambiguous',
                'owner_token' => null,
                'lease_expires_at' => null,
                'ambiguous_at' => $databaseNow,
                'terminal_reason' => $reason,
                'status' => 'ambiguous',
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'reported_model' => $reportedModel,
                'price_snapshot' => json_encode($priceSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => $databaseNow,
            ]);
        if ($updated !== 1) {
            throw new UsageInvariantViolation('Vision physical attempt ambiguous state collision.');
        }
    }

    public function markUsageRecorded(string $attemptId, string $requestFingerprint): void
    {
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->whereIn('state', ['response_received', 'ambiguous'])
            ->where('usage_recorded', false)
            ->update([
                'state' => $this->database->raw("CASE WHEN state = 'response_received' THEN 'completed' ELSE state END"),
                'usage_recorded' => true,
                'updated_at' => $this->databaseTime(new DateTimeImmutable),
            ]);
        if ($updated !== 1) {
            throw new UsageInvariantViolation('Vision physical attempt usage state collision.');
        }
    }

    private function snapshot(object $row): VisionPhysicalAttemptSnapshot
    {
        return new VisionPhysicalAttemptSnapshot(
            false,
            (string) $row->state,
            $row->response_payload === null ? null : $this->decode((string) $row->response_payload),
            $row->status === null ? null : (string) $row->status,
            $row->http_code === null ? null : (int) $row->http_code,
            $row->duration_ms === null ? null : (int) $row->duration_ms,
            $row->reported_model === null ? null : (string) $row->reported_model,
            $row->price_snapshot === null ? null : $this->decode((string) $row->price_snapshot),
            (bool) $row->usage_recorded,
            $row->owner_token === null ? null : (string) $row->owner_token,
            $row->lease_expires_at === null ? null : new DateTimeImmutable((string) $row->lease_expires_at),
            $row->terminal_reason === null ? null : (string) $row->terminal_reason,
        );
    }

    private function databaseTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new UsageInvariantViolation('Vision physical attempt payload is invalid.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function payloadFingerprint(array $payload): string
    {
        $sort = static function (array &$value) use (&$sort): void {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
