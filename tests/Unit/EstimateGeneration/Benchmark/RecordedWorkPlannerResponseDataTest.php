<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortEnvelopeException;
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
                    'quantity_source_refs' => ['evidence:quantity:foundation-volume'],
                    'confidence' => 0.92,
                ]],
            ]],
        ];
    }
}
