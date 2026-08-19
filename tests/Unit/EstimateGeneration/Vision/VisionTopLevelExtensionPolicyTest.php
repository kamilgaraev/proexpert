<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisionTopLevelExtensionPolicyTest extends TestCase
{
    #[Test]
    public function safe_diagnostic_extension_is_quarantined_without_losing_useful_v3_payload(): void
    {
        $payload = $this->payload();
        $payload['diagnostic_note'] = 'not used for estimation';

        $analysis = $this->parse($payload);

        self::assertCount(1, $analysis->evidence);
        self::assertCount(1, $analysis->elements);
        self::assertSame('room', $analysis->projectSheetAnalysis?->facts[0]['factType']);
        self::assertContains(
            ['section' => 'top_level_extension', 'index' => 0, 'reason' => 'safe_extension_ignored'],
            $analysis->quarantinedItems,
        );
    }

    #[Test]
    public function safe_diagnostic_extension_preserves_adaptive_v4_routing(): void
    {
        $payload = $this->payload();
        $payload['schema_version'] = 4;
        $payload['analysis_routing'] = [
            'page_kind' => 'drawing',
            'requested_depth' => 'dense_ambiguous',
            'information_density' => 'high',
            'readability' => 'high',
            'confidence' => 0.99,
            'ambiguous' => false,
            'material_risk' => 'high',
            'reasons' => ['Содержательный чертёж.'],
            'semantic_regions' => [],
        ];
        $payload['extension_trace'] = ['recording_phase' => 'recorded'];

        $analysis = $this->parse($payload);

        self::assertSame('dense_ambiguous', $analysis->analysisRouting?->effectiveRoute->value);
        self::assertCount(1, $analysis->projectSheetAnalysis?->facts ?? []);
        self::assertContains('safe_extension_ignored', array_column($analysis->quarantinedItems, 'reason'));
    }

    #[Test]
    public function reserved_or_nested_scope_override_is_fail_closed(): void
    {
        foreach ([
            ['source_version' => 'sha256:'.str_repeat('b', 64)],
            ['diagnostic_context' => ['project_id' => 999]],
            ['diagnostic_project_id' => 999],
            ['extension_security' => ['authorization' => 'bypass']],
            ['extension_scope' => ['organizationId' => 999, 'sourceVersion' => 'forged']],
        ] as $extension) {
            try {
                $this->parse([...$this->payload(), ...$extension]);
                self::fail('Unsafe extension was accepted.');
            } catch (VisionContractException $exception) {
                self::assertSame('unsafe_analysis_extension', $exception->reason);
            }
        }
    }

    #[Test]
    public function extension_count_depth_and_bytes_are_bounded(): void
    {
        $payloads = [
            [...$this->payload(), ...array_fill_keys(array_map(static fn (int $i): string => 'diagnostic_'.$i, range(1, 9)), 'ok')],
            [...$this->payload(), 'diagnostic_nested' => ['a' => ['b' => ['c' => ['d' => 'too deep']]]]],
            [...$this->payload(), 'diagnostic_blob' => str_repeat('x', 16_385)],
        ];

        foreach ($payloads as $payload) {
            try {
                $this->parse($payload);
                self::fail('Oversized extension was accepted.');
            } catch (VisionContractException $exception) {
                self::assertSame('analysis_extension_limit_exceeded', $exception->reason);
            }
        }
    }

    #[Test]
    public function malformed_known_field_is_not_hidden_by_safe_extension(): void
    {
        $payload = $this->payload();
        $payload['diagnostic_note'] = 'safe';
        $payload['elements'] = 'invalid';

        $this->expectException(VisionContractException::class);
        $this->expectExceptionMessage('invalid_analysis_schema');

        $this->parse($payload);
    }

    /** @param array<string, mixed> $payload */
    private function parse(array $payload): VisionAnalysisData
    {
        return VisionAnalysisData::fromProviderArray(
            $payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'vision-v3',
            'measured',
            100,
            30,
            64,
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $locator = [
            'page_id' => 17,
            'page_number' => 2,
            'processing_unit_id' => 19,
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'coordinate_space' => 'normalized_derivative_v1',
        ];

        return [
            'schema_version' => 3,
            'sheet_type' => 'floor_plan',
            'evidence' => [['key' => 'page', 'locator' => $locator]],
            'elements' => [[
                'key' => 'room-1',
                'type' => 'room',
                'label' => 'Кухня',
                'polygon' => [[0.1, 0.1], [0.4, 0.1], [0.4, 0.4], [0.1, 0.4]],
                'confidence' => 0.9,
                'evidence_ref' => 'page',
            ]],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => [],
            'project_sheet_analysis' => [
                'contractVersion' => 'sheet-analysis:v3',
                'role' => 'plan',
                'facts' => [[
                    'entityKey' => 'room.kitchen',
                    'factType' => 'room',
                    'value' => ['type' => 'string', 'data' => 'Кухня'],
                    'unit' => null,
                    'evidenceRef' => 'page',
                    'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.4, 0.1], [0.4, 0.4], [0.1, 0.4]],
                    'confidence' => 0.9,
                    'contractVersion' => 'sheet-analysis:v3',
                ]],
            ],
        ];
    }
}
