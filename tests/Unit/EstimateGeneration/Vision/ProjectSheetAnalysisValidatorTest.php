<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
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
    public function it_rejects_unknown_keys_missing_evidence_and_broken_coordinates(): void
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

            try {
                ProjectSheetAnalysisData::fromProviderArray($payload, ['page-1']);
                self::fail('Expected a strict schema violation for '.$case);
            } catch (VisionContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_a_fact_that_does_not_belong_to_the_selected_role_contract(): void
    {
        $this->expectException(VisionContractException::class);

        ProjectSheetAnalysisData::fromProviderArray($this->payload('facade', 'room'), ['page-1']);
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
            ProjectSheetAnalysisData::fromProviderArray($native, ['page-1'])
                ->mapPolygonsToSource($transform)->facts[0]['sourcePolygonOrNativeRef'],
        );
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
                'value' => ['type' => 'number', 'data' => 7.94],
                'unit' => 'm2',
                'evidenceRef' => 'page-1',
                'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.8, 0.8]],
                'confidence' => 0.91,
                'contractVersion' => ProjectSheetAnalysisData::CONTRACT_VERSION,
            ]],
        ];
    }
}
