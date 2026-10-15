<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class NormativeWorkItemPlannerServiceTest extends TestCase
{
    public function test_explicit_source_backed_suspended_ceiling_is_kept_in_both_finishing_packages(): void
    {
        foreach (['finish_finishing', 'office_finishing'] as $package) {
            $items = $this->planner()->build(['key' => $package, 'scope_type' => 'finishing'],
                ['key' => 'finish', 'construction_part' => 'finishing'], [
                    'object' => ['object_type' => 'office'], 'evaluation_policy' => ['mode' => 'universal'],
                    'document_context' => ['quantity_takeoffs' => [[
                        'scope_key' => 'ceiling_finish_area', 'name' => 'Подвесной потолок', 'quantity' => 20, 'unit' => 'м2',
                        'source_refs' => [['document_id' => 42, 'page_number' => 1]],
                        'normalized_payload' => ['quantity_key' => 'office.ceiling'],
                    ]]],
                ]);
            $ceilings = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === 'office.ceiling'));
            self::assertCount(1, $ceilings, $package);
            self::assertSame('20', $ceilings[0]['quantity']);
            self::assertSame(42, $ceilings[0]['source_refs'][0]['document_id']);
        }
    }

    public function test_office_floor_area_does_not_accept_a_suspended_ceiling_technology(): void
    {
        $section = ['key' => 'finish', 'construction_part' => 'finishing'];
        $items = $this->planner()->build(['key' => 'finish_finishing', 'scope_type' => 'finishing'], $section, [
            'object' => ['object_type' => 'office', 'description' => 'Офис'],
            'evaluation_policy' => ['mode' => 'universal'],
            'document_context' => ['facts_summary' => ['zones' => [['scope_key' => 'office', 'area_m2' => 50,
                'label' => 'Площадь помещения', 'source_ref' => ['document_id' => 42]]]]],
        ]);
        $ceilings = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === 'office.ceiling'));
        self::assertSame([], $ceilings, 'The room type and floor area do not select a suspended-ceiling technology.');
    }

    public function test_ceiling_takeoff_without_source_is_not_treated_as_an_explicit_technology(): void
    {
        $items = $this->planner()->build(['key' => 'office_finishing', 'scope_type' => 'finishing'],
            ['key' => 'finish', 'construction_part' => 'finishing'], [
                'object' => ['object_type' => 'office'], 'evaluation_policy' => ['mode' => 'universal'],
                'document_context' => ['quantity_takeoffs' => [[
                    'scope_key' => 'ceiling_finish_area', 'name' => 'Подвесной потолок', 'quantity' => 20, 'unit' => 'м2',
                    'source_refs' => [], 'normalized_payload' => ['quantity_key' => 'office.ceiling'],
                ]]],
            ]);
        self::assertSame([], array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['quantity_key'] ?? null) === 'office.ceiling')));
    }

    public function test_source_reference_without_quantity_remains_a_review_item_without_fabricated_cost(): void
    {
        foreach (['ФЕР08-02-001-01 Кладка стен', 'ФЕР08-02-001-01 Кладка стен м2'] as $line) {
            $section = ['key' => 'walls-section', 'construction_part' => 'walls'];
            $items = $this->planner()->build(['key' => 'walls', 'scope_type' => 'walls'], $section, [
                'source_documents' => [[
                    'id' => 82, 'filename' => 'volumes.pdf', 'status' => 'ready',
                    'quality' => ['level' => 'good'],
                    'document_understanding' => ['role_for_estimation' => 'quantity_source'], 'text' => $line,
                ]],
            ]);
            $references = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference'));
            self::assertCount(1, $references);
            $item = $references[0];
            self::assertNull($item['quantity']);
            self::assertNull($item['total_cost']);
            self::assertSame('quantity_review', $item['item_type']);
            self::assertSame('not_applicable', $item['pricing_status']);
            self::assertSame('quantity_review_required', $item['pricing_blocker']);
            self::assertContains('quantity_review_required', $item['validation_flags']);
            self::assertSame(82, $item['source_refs'][0]['document_id']);
            self::assertSame($line, $item['source_refs'][0]['excerpt']);
            $projection = (new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter))->projectionForDraft([
                'local_estimates' => [['key' => 'walls', 'sections' => [[...$section, 'work_items' => [$item]]]]],
            ]);
            self::assertCount(1, $projection);
            self::assertSame('confirm_quantity', $projection[0]['required_action']);
        }
    }

    public function test_document_rows_with_one_norm_keep_their_quantities_and_require_explicit_review(): void
    {
        foreach ([[10, 20], [10, 10]] as $quantities) {
            $documents = [];
            foreach ($quantities as $index => $quantity) {
                $documents[] = ['id' => 82 + $index, 'filename' => 'volumes-'.($index + 1).'.pdf',
                    'status' => 'ready', 'quality' => ['level' => 'good'],
                    'document_understanding' => ['role_for_estimation' => 'quantity_source'],
                    'text' => 'ФЕР 08-02-001-01 Кладка стен '.$quantity.' м2'];
            }
            $section = ['key' => 'walls-section', 'construction_part' => 'walls'];
            $items = $this->planner()->build(['key' => 'walls', 'scope_type' => 'walls'], $section,
                ['source_documents' => $documents]);
            $references = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference'));

            self::assertCount(2, $references);
            self::assertSame(array_map('strval', $quantities), array_column($references, 'quantity'));
            self::assertNotSame($references[0]['key'], $references[1]['key']);
            foreach ($references as $index => $item) {
                self::assertSame(82 + $index, $item['source_refs'][0]['document_id']);
                self::assertSame(1, $item['source_refs'][0]['line_number']);
                self::assertSame('quantity_review', $item['item_type']);
                self::assertSame('quantity_review_required', $item['pricing_blocker']);
                self::assertContains('quantity_review_required', $item['validation_flags']);
            }
            $review = (new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter))->projectionForDraft([
                'local_estimates' => [['key' => 'walls', 'sections' => [
                    [...$section, 'work_items' => $references],
                ]]],
            ]);
            self::assertCount(2, $review);
            self::assertSame(['confirm_quantity', 'confirm_quantity'], array_column($review, 'required_action'));
            self::assertSame(['blocking', 'blocking'], array_column($review, 'severity'));
        }
    }

    public function test_industrial_scope_without_dimensions_keeps_named_work_and_units_with_unknown_volume(): void
    {
        foreach (['process_pipelines' => 2, 'automation' => 2, 'equipment' => 3, 'commissioning' => 1] as $package => $minimum) {
            $section = ['key' => $package.'-section', 'title' => 'Раздел', 'construction_part' => 'engineering'];
            $items = $this->planner()->build(['key' => $package, 'scope_type' => 'engineering', 'sections' => [$section]], $section,
                ['evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'industrial']]);
            self::assertGreaterThanOrEqual($minimum, count($items));
            foreach ($items as $item) {
                self::assertNull($item['quantity']);
                self::assertNull($item['total_cost']);
                self::assertContains($item['unit'], ['m', 'count']);
                self::assertNotEmpty($item['metadata']['requirement_key']);
                self::assertTrue($item['metadata']['requires_specification']);
            }
        }
    }

    public function test_fsbc_resource_reference_stays_review_item_without_work_norm_code(): void
    {
        $items = $this->planner()->build(
            [
                'key' => 'materials',
                'scope_type' => 'custom',
                'sections' => [[
                    'key' => 'materials-section',
                    'title' => 'Материалы',
                    'construction_part' => 'custom',
                ]],
            ],
            [
                'key' => 'materials-section',
                'title' => 'Материалы',
                'construction_part' => 'custom',
            ],
            [
                'source_documents' => [[
                    'id' => 83,
                    'filename' => 'material-specification.pdf',
                    'status' => 'ready',
                    'quality' => ['level' => 'good'],
                    'text' => 'ФСБЦ 01.1.01.01-0001 Бетон тяжелый 12 м3',
                    'document_understanding' => [
                        'role_for_estimation' => 'quantity_source',
                    ],
                ]],
            ]
        );

        $resourceItem = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['metadata']['normative_resource_code'] ?? null) === '01.1.01.01-0001'
        ))[0] ?? null;

        self::assertIsArray($resourceItem);
        self::assertSame('priced_work', $resourceItem['item_type']);
        self::assertNull($resourceItem['normative_rate_code']);
        self::assertSame('01.1.01.01-0001', $resourceItem['metadata']['normative_resource_code']);
        self::assertSame('fsbc_resource', $resourceItem['metadata']['normative_reference_kind']);
        self::assertTrue($resourceItem['metadata']['requires_work_norm_selection']);
        self::assertContains('normative_code_required', $resourceItem['validation_flags']);
        self::assertSame('not_calculated', $resourceItem['pricing_status']);
        self::assertSame('normative_required', $resourceItem['pricing_blocker']);
        self::assertSame('12', $resourceItem['quantity']);
        self::assertSame('м3', $resourceItem['unit']);
        self::assertSame(83, $resourceItem['source_refs'][0]['document_id']);
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
        );
    }
}
