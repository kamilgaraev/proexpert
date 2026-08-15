<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class DurableAiPhysicalResponseStore
{
    private const TABLE = 'estimate_generation_vision_physical_attempts';

    public function __construct(private Connection $database) {}

    /** @return array{parsed_response:array<string,mixed>,provider_response:array<string,mixed>,usage_recorded:bool}|null */
    public function replay(string $attemptId, string $requestFingerprint): ?array
    {
        $row = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->whereIn('state', ['response_received', 'completed'])
            ->first(['response_payload', 'usage_recorded']);
        if ($row === null || ! is_string($row->response_payload)) {
            return null;
        }
        $payload = json_decode($row->response_payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)
            || ! is_array($payload['parsed_response'] ?? null)
            || ! is_array($payload['provider_response'] ?? null)) {
            throw new UsageInvariantViolation('AI physical response replay payload is invalid.');
        }

        return [
            'parsed_response' => $payload['parsed_response'],
            'provider_response' => $payload['provider_response'],
            'usage_recorded' => (bool) $row->usage_recorded,
        ];
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
        $now = new DateTimeImmutable;
        $payload = [
            'parsed_response' => $parsedResponse,
            'provider_response' => $this->providerEnvelope($providerResponse),
        ];
        $updated = $this->database->table(self::TABLE)
            ->where('attempt_id', $attemptId)
            ->where('request_fingerprint', $requestFingerprint)
            ->where('state', 'wire_started')
            ->update([
                'state' => 'response_received',
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
        $replay = $this->replay($attemptId, $requestFingerprint);
        if ($replay === null || $this->fingerprint($replay['parsed_response']) !== $this->fingerprint($parsedResponse)) {
            throw new UsageInvariantViolation('AI physical response collision.');
        }
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
        ];
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
