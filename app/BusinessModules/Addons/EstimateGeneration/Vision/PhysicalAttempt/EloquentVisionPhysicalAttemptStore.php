<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use Illuminate\Database\Connection;

final readonly class EloquentVisionPhysicalAttemptStore implements VisionPhysicalAttemptStore
{
    private const TABLE = 'estimate_generation_vision_physical_attempts';

    public function __construct(private Connection $database) {}

    public function reserve(AiOperationContext $context, string $requestFingerprint): VisionPhysicalAttemptSnapshot
    {
        $inserted = $this->database->table(self::TABLE)->insertOrIgnore([
            'attempt_id' => $context->attemptId,
            'request_fingerprint' => $requestFingerprint,
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'session_id' => $context->sessionId,
            'document_id' => $context->documentId,
            'page_id' => $context->pageId,
            'unit_id' => $context->unitId,
            'state' => 'reserved',
            'usage_recorded' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = $this->database->table(self::TABLE)->where('attempt_id', $context->attemptId)->first();
        if ($row === null || ! hash_equals((string) $row->request_fingerprint, $requestFingerprint)) {
            throw new UsageInvariantViolation('Vision physical attempt collision.');
        }

        return new VisionPhysicalAttemptSnapshot(
            $inserted === 1,
            (string) $row->state,
            $row->response_payload === null ? null : $this->decode((string) $row->response_payload),
            $row->status === null ? null : (string) $row->status,
            $row->http_code === null ? null : (int) $row->http_code,
            $row->duration_ms === null ? null : (int) $row->duration_ms,
            $row->reported_model === null ? null : (string) $row->reported_model,
            $row->price_snapshot === null ? null : $this->decode((string) $row->price_snapshot),
            (bool) $row->usage_recorded,
        );
    }

    public function storeResponse(
        string $attemptId,
        string $requestFingerprint,
        array $responsePayload,
        string $status,
        ?int $httpCode,
        int $durationMs,
        ?string $reportedModel,
        array $priceSnapshot,
    ): void {
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'reserved')
            ->update([
                'state' => 'response_received',
                'response_payload' => json_encode($responsePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'status' => $status,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'reported_model' => $reportedModel,
                'price_snapshot' => json_encode($priceSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            $row = $this->database->table(self::TABLE)->where('attempt_id', $attemptId)->first();
            if ($row === null || ! hash_equals((string) $row->request_fingerprint, $requestFingerprint)
                || (string) $row->state !== 'response_received'
                || ! hash_equals($this->payloadFingerprint($this->decode((string) $row->response_payload)), $this->payloadFingerprint($responsePayload))) {
                throw new UsageInvariantViolation('Vision physical response collision.');
            }
        }
    }

    public function markUsageRecorded(string $attemptId, string $requestFingerprint): void
    {
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'response_received')
            ->update(['usage_recorded' => true, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new UsageInvariantViolation('Vision physical attempt usage state collision.');
        }
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
