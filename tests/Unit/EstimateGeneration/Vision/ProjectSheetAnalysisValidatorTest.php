<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\ProjectSheetAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\FacadeSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\PlanSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\SectionSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\SpecificationSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\UnknownSheetAnalysis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseLessTestCase;

final class ProjectSheetAnalysisValidatorTest extends DatabaseLessTestCase
{
    #[Test]
    #[DataProvider('sheetExamples')]
    public function it_builds_one_typed_contract_for_every_supported_role(string $role, string $factType, string $expectedClass): void
    {
        $analysis = ProjectSheetAnalysisData::fromProviderArray($this->payload($role, $factType), ['page-1']);

        self::assertInstanceOf($expectedClass, $analysis->roleAnalysis);
        self::assertSame($role, $analysis->sheetRole);
        self::assertSame($factType, $analysis->facts[0]['factType']);
        self::assertSame(ProjectSheetAnalysisData::CONTRACT_VERSION, $analysis->facts[0]['contractVersion']);
    }

    #[Test]
    public function it_quarantines_unknown_keys_missing_evidence_and_broken_coordinates(): void
    {
        foreach (['unknown_key', 'missing_evidence', 'geometry'] as $case) {
            $payload = $this->payload();
            if ($case === 'unknown_key') {
                $payload['facts'][0]['legacyKey'] = true;
            } elseif ($case === 'missing_evidence') {
                $payload['facts'][0]['evidenceRef'] = 'missing';
            } else {
                $payload['facts'][0]['sourcePolygonOrNativeRef'][1] = [1.01, 0.8];
            }

            $analysis = ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);
            self::assertSame([], $analysis->facts, $case);
            self::assertCount(1, $analysis->quarantinedItems, $case);
        }
    }

    #[Test]
    public function numeric_facts_require_canonical_decimal_strings_and_isolate_one_malformed_item(): void
    {
        $payload = $this->payload();
        $payload['facts'] = [];
        for ($index = 0; $index < 30; $index++) {
            $fact = $this->payload()['facts'][0];
            $fact['entityKey'] = 'room-'.$index;
            $fact['value']['data'] = (string) ($index + 1).'.25';
            $payload['facts'][] = $fact;
        }
        $payload['facts'][14]['value']['data'] = 15.25;

        $analysis = ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);

        self::assertCount(29, $analysis->facts);
        self::assertSame('room-13', $analysis->facts[13]['entityKey']);
        self::assertSame('room-15', $analysis->facts[14]['entityKey']);
        self::assertSame([[
            'section' => 'facts',
            'index' => 14,
            'reason' => 'invalid_project_sheet_value',
        ]], $analysis->quarantinedItems);

        foreach (['1e3', 'NaN', 'Infinity', '-0', '-0.0', '+1', '01', '.5', '1.', '1000000000001', '-1000000000001', '0.12345'] as $invalid) {
            $candidate = $this->payload();
            $candidate['facts'][0]['value']['data'] = $invalid;
            $result = ProjectSheetAnalysisData::fromProviderArray($candidate, ['page-1']);
            self::assertSame([], $result->facts, $invalid);
            self::assertSame('invalid_project_sheet_value', $result->quarantinedItems[0]['reason'], $invalid);
        }
    }

    #[Test]
    public function it_preserves_an_unknown_professional_fact_for_arbitration_without_confirming_it(): void
    {
        $analysis = ProjectSheetAnalysisData::fromProviderArray($this->payload('facade', 'room'), ['page-1']);

        self::assertSame([], $analysis->facts);
        self::assertSame('unregistered_project_sheet_fact_type', $analysis->quarantinedItems[0]['reason']);
    }

    #[Test]
    public function unknown_role_is_a_typed_empty_review_contract(): void
    {
        $payload = [
            'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
            'role' => 'unknown',
            'facts' => [],
        ];

        $analysis = ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);

        self::assertInstanceOf(UnknownSheetAnalysis::class, $analysis->roleAnalysis);
        self::assertSame([], $analysis->facts);
    }

    #[Test]
    public function it_maps_only_polygon_sources_and_keeps_native_references_stable(): void
    {
        $analysis = ProjectSheetAnalysisData::fromProviderArray($this->payload(), ['page-1']);
        $transform = new ProjectiveTransformData(
            [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            [[2.0, 0.0, 10.0], [0.0, 3.0, 20.0], [0.0, 0.0, 1.0]],
            1.0,
            1.0,
        );

        $mapped = $analysis->mapPolygonsToSource($transform);

        self::assertSame([10.2, 20.3], $mapped->facts[0]['sourcePolygonOrNativeRef'][0]);
        self::assertSame([11.6, 22.4], $mapped->facts[0]['sourcePolygonOrNativeRef'][1]);

        $native = $this->payload('specification', 'table');
        $native['facts'][0]['sourcePolygonOrNativeRef'] = 'xlsx:sheet:Спецификация!A2:D8';
        self::assertSame(
            'xlsx:sheet:Спецификация!A2:D8',
            ProjectSheetAnalysisData::fromProviderArray($native, ['page-1'], 500, ['xlsx:sheet:Спецификация!A2:D8'])
                ->mapPolygonsToSource($transform)->facts[0]['sourcePolygonOrNativeRef'],
        );
    }

    #[Test]
    public function it_quarantines_a_well_formed_native_reference_that_is_absent_from_the_published_registry(): void
    {
        $native = $this->payload('specification', 'table');
        $native['facts'][0]['sourcePolygonOrNativeRef'] = 'xlsx:sheet:Спецификация!Z999';

        $analysis = ProjectSheetAnalysisData::fromProviderArray(
            $native,
            ['page-1'],
            500,
            ['xlsx:sheet:Спецификация!A2:D8'],
        );
        self::assertSame([], $analysis->facts);
        self::assertSame('invalid_project_sheet_native_reference', $analysis->quarantinedItems[0]['reason']);
    }

    #[Test]
    #[DataProvider('nativeReferenceRegistries')]
    public function native_reference_must_match_the_exact_published_object(string $published, string $hallucinated): void
    {
        $native = $this->payload();
        $native['facts'][0]['sourcePolygonOrNativeRef'] = $published;
        self::assertSame(
            $published,
            ProjectSheetAnalysisData::fromProviderArray($native, ['page-1'], 500, [$published])
                ->facts[0]['sourcePolygonOrNativeRef'],
        );

        $native['facts'][0]['sourcePolygonOrNativeRef'] = $hallucinated;
        $analysis = ProjectSheetAnalysisData::fromProviderArray($native, ['page-1'], 500, [$published]);
        self::assertSame([], $analysis->facts);
        self::assertSame('invalid_project_sheet_native_reference', $analysis->quarantinedItems[0]['reason']);
    }

    #[Test]
    public function reference_from_a_stale_source_registry_is_rejected_against_the_current_call_registry(): void
    {
        $current = 'cad:object:current-v2';
        $stale = 'cad:object:stale-v1';
        $native = $this->payload();
        $native['facts'][0]['sourcePolygonOrNativeRef'] = $stale;

        $analysis = ProjectSheetAnalysisData::fromProviderArray($native, ['page-1'], 500, [$current]);
        self::assertSame([], $analysis->facts);
        self::assertSame('invalid_project_sheet_native_reference', $analysis->quarantinedItems[0]['reason']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nativeReferenceRegistries(): iterable
    {
        yield 'cad' => ['cad:object:2F', 'cad:object:30'];
        yield 'xlsx' => ['xlsx:sheet:Лист1!A2', 'xlsx:sheet:Лист1!A3'];
    }

    /** @return iterable<string, array{string, string, class-string}> */
    public static function sheetExamples(): iterable
    {
        yield 'plan' => ['plan', 'room', PlanSheetAnalysis::class];
        yield 'section' => ['section', 'dimension_chain', SectionSheetAnalysis::class];
        yield 'facade' => ['facade', 'structural_element', FacadeSheetAnalysis::class];
        yield 'explication' => ['explication', 'table', SpecificationSheetAnalysis::class];
        yield 'specification' => ['specification', 'table', SpecificationSheetAnalysis::class];
    }

    /** @return array<string, mixed> */
    private function payload(string $role = 'plan', string $factType = 'room'): array
    {
        return [
            'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
            'role' => $role,
            'facts' => [[
                'entityKey' => 'room-1',
                'factType' => $factType,
                'value' => ['type' => 'number', 'data' => '7.94'],
                'unit' => 'm2',
                'evidenceRef' => 'page-1',
                'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.8, 0.8]],
                'confidence' => 0.91,
                'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
            ]],
        ];
    }
}
