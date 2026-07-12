<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPort;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelope;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedWorkPlannerProvider;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedWorkPlannerResponseData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedWorkPlannerResponseDataTest extends TestCase
{
    #[Test]
    public function accepts_closed_semantic_work_intents_with_quantity_evidence(): void
    {
        $response = RecordedWorkPlannerResponseData::fromProviderArray($this->payload());

        self::assertSame('foundation', $response->sections[0]['scope_type']);
        self::assertSame('m3', $response->sections[0]['work_intents'][0]['unit']);
    }

    #[Test]
    public function rejects_oracle_identity_price_and_readiness_fields(): void
    {
        foreach (['work_id', 'norm_id', 'price', 'readiness', 'expected'] as $field) {
            $payload = $this->payload();
            $payload['sections'][0]['work_intents'][0][$field] = 'oracle';

            try {
                RecordedWorkPlannerResponseData::fromProviderArray($payload);
                self::fail($field.' was accepted');
            } catch (RecordedPortEnvelopeException $exception) {
                self::assertSame('recorded_work_planner_contract_invalid', $exception->reason);
            }
        }
    }

    #[Test]
    public function recorded_provider_exposes_only_validated_work_planner_response(): void
    {
        $provider = new RecordedWorkPlannerProvider($this->envelope(RecordedPort::WorkPlanningModel));

        $response = $provider->provide();

        self::assertSame('concrete-foundation', $response->sections[0]['work_intents'][0]['intent_key']);
    }

    #[Test]
    public function recorded_provider_rejects_an_envelope_from_another_port(): void
    {
        $this->expectException(RecordedPortEnvelopeException::class);
        $this->expectExceptionMessage('recorded_work_planner_port_invalid');

        new RecordedWorkPlannerProvider($this->envelope(RecordedPort::VisionExtraction));
    }

    #[Test]
    public function rejects_duplicate_quantity_mapping_across_intents(): void
    {
        $payload = $this->payload();
        $duplicate = $payload['sections'][0]['work_intents'][0];
        $duplicate['intent_key'] = 'second-intent';
        $payload['sections'][0]['work_intents'][] = $duplicate;

        $this->expectException(RecordedPortEnvelopeException::class);
        $this->expectExceptionMessage('recorded_work_planner_contract_invalid');
        RecordedWorkPlannerResponseData::fromProviderArray($payload);
    }

    private function envelope(RecordedPort $port): RecordedPortEnvelope
    {
        return new RecordedPortEnvelope(
            1, $port, str_repeat('a', 64), str_repeat('b', 64), 'fixture-provider', 'model-v1',
            'prompt-v1', 'work-planner-v1', $this->payload(), str_repeat('c', 64),
            'most-fixture-privacy', 'scanner-v1', 'review:task11', '2026-07-12T00:00:00Z', str_repeat('d', 64),
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 'work-planner-v1',
            'sections' => [[
                'section_key' => 'foundation',
                'title' => 'Фундамент',
                'scope_type' => 'foundation',
                'source_refs' => ['evidence:geometry:1'],
                'work_intents' => [[
                    'intent_key' => 'concrete-foundation',
                    'name' => 'Устройство бетонного фундамента',
                    'category' => 'foundation',
                    'unit' => 'm3',
                    'quantity' => '12.5',
                    'quantity_key' => 'foundation_volume', 'quantity_source_refs' => ['evidence:quantity:foundation-volume'],
                    'confidence' => 0.92,
                ]],
            ]],
        ];
    }
}
