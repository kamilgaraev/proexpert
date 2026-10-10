<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Throwable;

final readonly class DurableAiPhysicalResponseStore
{
    private const TABLE = 'estimate_generation_vision_physical_attempts';

    public function __construct(private Connection $database) {}

    public function replayWire(int $organizationId, int $projectId, int $sessionId, string $attemptId, string $requestFingerprint): ?array
    {
        $row = $this->database->table(self::TABLE)->where('attempt_id', $attemptId)
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->where('request_fingerprint', $requestFingerprint)->whereIn('state', ['response_received', 'completed'])
            ->first(['response_payload', 'duration_ms', 'price_snapshot', 'usage_recorded']);
        if ($row === null || ! is_string($row->response_payload)) {
            return null;
        }
        $payload = json_decode($row->response_payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload['wire_response'] ?? null)) {
            return null;
        }

        return [...$payload['wire_response'], 'physical_attempt_receipt' => [
            'duration_ms' => (int) $row->duration_ms,
            'price_snapshot' => json_decode((string) $row->price_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'usage_recorded' => (bool) $row->usage_recorded,
        ]];
    }

    public function storeWire(int $organizationId, int $projectId, int $sessionId, string $attemptId, string $requestFingerprint, array $response, int $durationMs, array $priceSnapshot): void
    {
        $encoded = json_encode(['wire_response' => $response], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 2_097_152) {
            throw new UsageInvariantViolation('Text AI physical response exceeds storage limit.');
        }
        $now = new DateTimeImmutable;
        $updated = $this->database->table(self::TABLE)->where('attempt_id', $attemptId)
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->where('request_fingerprint', $requestFingerprint)->where('state', 'wire_started')->update([
                'state' => 'response_received', 'response_payload' => $encoded,
                'status' => 'succeeded', 'http_code' => 200, 'duration_ms' => $durationMs,
                'reported_model' => is_string($response['model'] ?? null) ? $response['model'] : null,
                'price_snapshot' => json_encode($priceSnapshot, JSON_THROW_ON_ERROR),
                'response_received_at' => $now, 'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new UsageInvariantViolation('Text AI physical response ownership lost.');
        }
    }

    /** @return array{parsed_response:array<string,mixed>,provider_response:array<string,mixed>,usage_recorded:bool,duration_ms:int,price_snapshot:array<string,string>}|null */
    public function replay(string $attemptId, string $requestFingerprint): ?array
    {
        $row = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->whereIn('state', ['response_received', 'completed'])
            ->first(['response_payload', 'usage_recorded', 'duration_ms', 'price_snapshot']);
        if ($row === null || ! is_string($row->response_payload)) {
            return null;
        }
        $payload = json_decode($row->response_payload, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($payload['wire_response'] ?? null) && ! isset($payload['parsed_response'])) {
            return null;
        }
        if (! is_array($payload) || ! is_array($payload['parsed_response'] ?? null)
            || ! is_array($payload['provider_response'] ?? null)) {
            throw new UsageInvariantViolation('AI physical response replay payload is invalid.');
        }

        return [
            'parsed_response' => $payload['parsed_response'],
            'provider_response' => $payload['provider_response'],
            'usage_recorded' => (bool) $row->usage_recorded,
            'duration_ms' => (int) $row->duration_ms,
            'price_snapshot' => json_decode((string) $row->price_snapshot, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function replayBeforeWire(string $attemptId, string $requestFingerprint): ?array
    {
        try {
            return $this->replay($attemptId, $requestFingerprint);
        } catch (Throwable) {
            throw new AiWireNotStarted('text_ai_response_replay_failed');
        }
    }

    /** @param array<string,mixed> $parsedResponse @param array<string,mixed> $providerResponse @param array<string,string> $priceSnapshot */
    public function store(
        string $attemptId,
        string $requestFingerprint,
        array $parsedResponse,
        array $providerResponse,
        int $durationMs,
        array $priceSnapshot,
    ): void {
        $this->database->transaction(function () use ($attemptId, $requestFingerprint, $parsedResponse, $providerResponse, $durationMs, $priceSnapshot): void {
            $now = new DateTimeImmutable;
            $existing = $this->database->table(self::TABLE)->where('attempt_id', $attemptId)
                ->where('request_fingerprint', $requestFingerprint)->whereIn('state', ['wire_started', 'response_received', 'completed'])
                ->lockForUpdate()->first(['response_payload', 'price_snapshot', 'duration_ms', 'state']);
            if ($existing === null) {
                throw new UsageInvariantViolation('AI physical response ownership lost.');
            }
            $wirePayload = is_string($existing->response_payload) && $existing->response_payload !== ''
                ? json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR) : [];
            if (is_array($wirePayload['parsed_response'] ?? null)) {
                if ($this->fingerprint($wirePayload['parsed_response']) !== $this->fingerprint($parsedResponse)
                    || $this->fingerprint($this->providerEnvelope($wirePayload['provider_response'] ?? [])) !== $this->fingerprint($this->providerEnvelope($providerResponse))) {
                    throw new UsageInvariantViolation('AI physical response collision.');
                }

                return;
            }
            if ((string) $existing->state === 'completed' && ! is_array($wirePayload['wire_response'] ?? null)) {
                throw new UsageInvariantViolation('AI physical response replay payload is invalid.');
            }
            if (is_array($wirePayload['wire_response'] ?? null)
                && $this->fingerprint($this->providerEnvelope($wirePayload['wire_response'])) !== $this->fingerprint($this->providerEnvelope($providerResponse))) {
                throw new UsageInvariantViolation('AI physical wire response collision.');
            }
            if (is_array($wirePayload['wire_response'] ?? null)) {
                $priceSnapshot = json_decode((string) $existing->price_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $durationMs = (int) $existing->duration_ms;
            }
            $payload = [
                ...(is_array($wirePayload['wire_response'] ?? null) ? ['wire_response' => $wirePayload['wire_response']] : []),
                'parsed_response' => $parsedResponse,
                'provider_response' => $this->providerEnvelope($providerResponse),
            ];
            $updated = $this->database->table(self::TABLE)
                ->where('attempt_id', $attemptId)
                ->where('request_fingerprint', $requestFingerprint)
                ->where('state', (string) $existing->state)
                ->update([
                    'state' => (string) $existing->state === 'completed' ? 'completed' : 'response_received',
                    'owner_token' => null,
                    'lease_expires_at' => null,
                    'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'status' => 'succeeded',
                    'http_code' => 200,
                    'duration_ms' => $durationMs,
                    'reported_model' => is_string($providerResponse['model'] ?? null) ? $providerResponse['model'] : null,
                    'price_snapshot' => json_encode($priceSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'response_received_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated === 1) {
                return;
            }
            throw new UsageInvariantViolation('AI physical response ownership lost.');
        }, 3);
    }

    public function markUsageRecorded(string $attemptId, string $requestFingerprint): void
    {
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'response_received')
            ->where('usage_recorded', false)
            ->update([
                'state' => 'completed',
                'usage_recorded' => true,
                'updated_at' => new DateTimeImmutable,
            ]);
        if ($updated === 1) {
            return;
        }
        $row = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->first(['state', 'usage_recorded']);
        if ($row === null || (string) $row->state !== 'completed' || ! (bool) $row->usage_recorded) {
            throw new UsageInvariantViolation('AI physical usage state collision.');
        }
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function providerEnvelope(array $response): array
    {
        return [
            'model' => is_string($response['model'] ?? null) ? $response['model'] : null,
            'usage_available' => ($response['usage_available'] ?? false) === true,
            'input_tokens' => max(0, (int) ($response['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($response['output_tokens'] ?? 0)),
            'cached_input_tokens' => max(0, (int) ($response['cached_input_tokens'] ?? 0)),
            'reasoning_tokens' => max(0, (int) ($response['reasoning_tokens'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
