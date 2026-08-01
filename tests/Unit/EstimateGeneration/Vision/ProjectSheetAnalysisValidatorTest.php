<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\ProjectSheetAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseLessTestCase;

final class ProjectSheetAnalysisValidatorTest extends DatabaseLessTestCase
{
    #[Test]
    #[DataProvider('sheetExamples')]
    public function it_accepts_evidenced_facts_for_each_supported_sheet_role(string $sheetRole, string $factType, array $value, ?string $unit): void
    {
        $analysis = ProjectSheetAnalysisData::fromProviderArray([
            'schema_version' => 1,
            'sheet_role' => $sheetRole,
            'facts' => [[
                'key' => 'fact-1', 'type' => $factType, 'evidence_ref' => 'page-1',
                'polygon' => [[0.1, 0.1], [0.8, 0.8]], 'confidence' => 0.91,
                'value' => $value, 'unit' => $unit,
            ]],
        ], ['page-1']);

        self::assertSame($sheetRole, $analysis->sheetRole);
        self::assertSame($factType, $analysis->facts[0]['type']);
    }

    #[Test]
    public function it_requires_explicit_unknown_instead_of_an_invented_value(): void
    {
        $payload = $this->payload();
        $payload['facts'][0]['value'] = ['type' => 'unknown', 'data' => 12];

        $this->expectException(VisionContractException::class);
        ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);
    }

    #[Test]
    public function it_rejects_unknown_keys_dangling_evidence_and_non_normalized_geometry(): void
    {
        foreach (['unknown_key', 'dangling_evidence', 'geometry'] as $case) {
            $payload = $this->payload();
            if ($case === 'unknown_key') {
                $payload['extra'] = true;
            } elseif ($case === 'dangling_evidence') {
                $payload['facts'][0]['evidence_ref'] = 'missing';
            } else {
                $payload['facts'][0]['polygon'][1] = [1.01, 0.8];
            }
            try {
                ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);
                self::fail('Expected a strict schema violation for '.$case);
            } catch (VisionContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_maps_project_sheet_fact_geometry_to_source_once(): void
    {
        $analysis = ProjectSheetAnalysisData::fromProviderArray($this->payload(), ['page-1']);
        $transform = new ProjectiveTransformData(
            [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            [[2.0, 0.0, 10.0], [0.0, 3.0, 20.0], [0.0, 0.0, 1.0]],
            1.0,
            1.0,
        );

        $mapped = $analysis->mapPolygonsToSource($transform);

        self::assertSame([10.2, 20.3], $mapped->facts[0]['polygon'][0]);
        self::assertSame([11.6, 22.4], $mapped->facts[0]['polygon'][1]);
    }

    #[Test]
    public function it_rejects_unknown_roles_units_for_unknowns_and_excessive_fact_count(): void
    {
        $cases = [];
        $unknownRole = $this->payload();
        $unknownRole['sheet_role'] = 'render';
        $cases[] = $unknownRole;
        $unknownUnit = $this->payload();
        $unknownUnit['facts'][0]['value'] = ['type' => 'unknown', 'data' => null];
        $unknownUnit['facts'][0]['unit'] = 'm';
        $cases[] = $unknownUnit;
        $overflow = $this->payload();
        $overflow['facts'] = array_fill(0, 501, $overflow['facts'][0]);
        $cases[] = $overflow;

        foreach ($cases as $payload) {
            try {
                ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);
                self::fail('Expected strict project-sheet contract violation.');
            } catch (VisionContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string, string, array{type: string, data: mixed}, ?string}> */
    public static function sheetExamples(): iterable
    {
        yield 'plan with explication room' => ['plan', 'room', ['type' => 'number', 'data' => 7.94], 'm2'];
        yield 'section with level chain' => ['section', 'dimension_chain', ['type' => 'number', 'data' => 6.5], 'm'];
        yield 'elevation with structural feature' => ['elevation', 'structural_element', ['type' => 'enum', 'data' => 'gable_roof'], null];
        yield 'specification table' => ['specification', 'table', ['type' => 'string', 'data' => 'оконный блок'], 'шт'];
        yield 'visual with explicit unknown' => ['visual', 'furniture', ['type' => 'unknown', 'data' => null], null];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'sheet_role' => 'plan',
            'facts' => [[
                'key' => 'fact-1', 'type' => 'room', 'evidence_ref' => 'page-1',
                'polygon' => [[0.1, 0.1], [0.8, 0.8]], 'confidence' => 0.91,
                'value' => ['type' => 'number', 'data' => 7.94], 'unit' => 'm2',
            ]],
        ];
    }
}
