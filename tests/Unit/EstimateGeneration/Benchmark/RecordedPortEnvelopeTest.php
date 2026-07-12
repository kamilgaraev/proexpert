<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelope;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedPortEnvelopeTest extends TestCase
{
    #[Test]
    public function approved_contract_fixture_round_trips_and_verifies_hashes(): void
    {
        $payload = ['sheet_type' => 'floor_plan', 'elements' => []];
        $envelope = RecordedPortEnvelope::fromArray($this->valid($payload));

        self::assertSame($payload, $envelope->payload);
        self::assertSame('vision_extraction', $envelope->port->value);
    }

    #[Test]
    public function oracle_and_sensitive_keys_are_rejected_recursively(): void
    {
        foreach (['expected', 'expected_path', 'expected_content', 'labels', 'metrics', 'final_prediction', 'readiness', 'price_total', 'access_token'] as $key) {
            try {
                RecordedPortEnvelope::fromArray($this->valid(['nested' => [$key => 'forbidden']]));
                self::fail("Key {$key} was accepted.");
            } catch (RecordedPortEnvelopeException $exception) {
                self::assertSame('recorded_payload_forbidden_key', $exception->reason);
            }
        }
    }

    #[Test]
    public function payload_tampering_is_rejected(): void
    {
        $data = $this->valid(['sheet_type' => 'floor_plan']);
        $data['payload']['sheet_type'] = 'oracle';

        $this->expectException(RecordedPortEnvelopeException::class);
        RecordedPortEnvelope::fromArray($data);
    }

    #[Test]
    public function privacy_result_is_closed_and_required(): void
    {
        $valid = $this->valid(['sheet_type' => 'floor_plan']);
        self::assertSame('passed', RecordedPortEnvelope::fromArray($valid)->privacyResult);

        unset($valid['privacy_result']);
        try {
            RecordedPortEnvelope::fromArray($valid);
            self::fail('Missing privacy result was accepted.');
        } catch (RecordedPortEnvelopeException $exception) {
            self::assertSame('recorded_envelope_contract_invalid', $exception->reason);
        }

        $unknown = $this->valid(['sheet_type' => 'floor_plan']);
        $unknown['privacy_result'] = 'unknown';
        $this->expectException(RecordedPortEnvelopeException::class);
        RecordedPortEnvelope::fromArray($unknown);
    }

    private function valid(array $payload): array
    {
        $canonical = static function (array $value): string {
            ksort($value, SORT_STRING);

            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        };
        $data = [
            'schema_version' => 1,
            'port' => 'vision_extraction',
            'source_sha256' => str_repeat('a', 64),
            'input_dependency_sha256' => str_repeat('b', 64),
            'provider' => 'timeweb',
            'model_version' => 'vision:test:v1',
            'prompt_version' => 'vision-prompt:v1',
            'payload_schema_version' => 'vision-analysis:v1',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $canonical($payload)),
            'privacy_scanner' => 'most-fixture-privacy',
            'privacy_scanner_version' => '1.0.0',
            'privacy_result' => 'passed',
            'capture_kind' => 'contract_fixture',
            'approval_kind' => 'maintainer_code_review',
            'approval_ref' => 'review:plan3-task11:independent-provider-output',
            'approved_at' => '2026-07-12T10:00:00Z',
            'manifest_sha256' => str_repeat('c', 64),
        ];

        return $data;
    }
}
