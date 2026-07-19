<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeWorkflowResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedNormativeDecisionDataTest extends TestCase
{
    #[Test]
    public function creates_a_closed_resource_decision_from_the_exact_workflow_selection(): void
    {
        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $this->catalogCandidate());

        self::assertSame(101, $decision->normativeId);
        self::assertSame('fsnb-2026.1', $decision->datasetVersion);
        self::assertCount(1, $decision->resources['materials']);
        self::assertSame(9001, $decision->resources['materials'][0]['price_id']);
    }

    #[Test]
    public function preserves_project_selected_abstract_resources_without_inventing_a_price(): void
    {
        $record = $this->catalogCandidate();
        $record['retrieval_metadata'] = [
            'unpriced_abstract_resources' => [[
                'resource_code' => '04.1.02.05',
                'name' => 'Смеси бетонные тяжелого бетона',
                'unit' => 'м3',
                'quantity' => 101.5,
                'reason' => 'project_resource_selection_required',
            ]],
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame('04.1.02.05', $decision->unpricedAbstractResources[0]['resource_code']);

        $service = new ResourceAssemblyService(
            $this->createMock(EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService,
            new NormativeCandidatePresenter,
        );
        $item = $service->assembleFromDecision(
            ['key' => 'work-1', 'name' => 'Монтаж стены', 'unit' => 'm2', 'quantity' => '2', 'confidence' => 0.8],
            $decision,
            ['dataset_id' => 77, 'dataset_version' => 'fsnb-2026.1', 'region_id' => 77,
                'price_zone_id' => 1, 'period_id' => 202606, 'price_version' => 'prices-2026.06',
                'estimate_regional_price_version_id' => 8],
        );

        self::assertSame('04.1.02.05', $item['normative_match']['unpriced_abstract_resources'][0]['resource_code']);
        self::assertContains('project_resource_selection_required', $item['normative_match']['warnings']);
        self::assertNotContains('missing_resources', $item['validation_flags']);
        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertSame('project_resource_selection_required', $item['pricing_blocker']);
        self::assertContains('project_resource_selection_required', $item['validation_flags']);
    }

    #[Test]
    public function exposes_the_concrete_regional_price_used_for_a_project_resource_group(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '04.1.02.05',
            'name' => 'Смеси бетонные по проекту',
            'unit_price' => '7450.250000',
            'norm_resource_id' => 7001,
            'price_source' => 'regional_catalog',
            'project_resource_selection' => [
                'group_code' => '04.1.02.05',
                'selected_resource_code' => '04.1.02.05-0123',
                'selected_resource_name' => 'Бетон В25 П4 F150 W6',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'prices-2026.06',
                'policy' => 'regional_child_median:v1',
                'candidates_count' => 7,
            ],
        ];
        $service = new ResourceAssemblyService(
            $this->createMock(EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService,
            new NormativeCandidatePresenter,
        );

        $item = $service->assembleFromDecision(
            ['key' => 'work-1', 'name' => 'Устройство конструкции', 'unit' => 'm2', 'quantity' => '2', 'confidence' => 0.8],
            AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record),
            ['dataset_id' => 77, 'dataset_version' => 'fsnb-2026.1', 'region_id' => 77,
                'price_zone_id' => 1, 'period_id' => 202606, 'price_version' => 'prices-2026.06',
                'estimate_regional_price_version_id' => 8],
        );

        self::assertContains('project_resource_price_assumption', $item['normative_match']['warnings']);
        self::assertSame('04.1.02.05-0123', $item['materials'][0]['project_resource_selection']['selected_resource_code']);
        self::assertSame('04.1.02.05-0123', $item['materials'][0]['normative_ref']['project_resource_selection']['selected_resource_code']);
        self::assertSame(9001, $item['normative_match']['project_resource_selections'][0]['price_id']);
        self::assertSame('7450.250000', $item['normative_match']['project_resource_selections'][0]['applied_unit_price']);
    }

    #[Test]
    public function accepts_a_strong_semantic_regional_project_resource_selection_with_an_unrelated_catalog_code(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '18.2.07.01',
            'name' => 'Трубопроводы с гильзами',
            'price_source' => 'regional_catalog',
            'unit_price' => '245.500000',
            'project_resource_selection' => [
                'group_code' => '18.2.07.01',
                'selected_resource_code' => '73.9.44.08',
                'selected_resource_name' => 'Труба ВГП стальная оцинкованная Ду 15',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'prices-2026.06',
                'policy' => 'regional_semantic_pipe_hard_attributes_median:v1',
                'candidates_count' => 3,
            ],
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame(
            '73.9.44.08',
            $decision->resources['materials'][0]['project_resource_selection']['selected_resource_code'],
        );
    }

    #[Test]
    public function accepts_a_semantic_metal_gutter_selection_with_an_exact_regional_price_source(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '08.1.02.22',
            'name' => 'Изделия для водосточных труб',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'prices-2026.06',
            'unit_price' => '274.750000',
            'project_resource_selection' => [
                'group_code' => '08.1.02.22',
                'selected_resource_code' => '12.1.01.05-0058',
                'selected_resource_name' => 'Соединитель желоба металлический для водосточных систем',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'prices-2026.06',
                'policy' => 'regional_semantic_metal_gutter_family_median:v1',
                'candidates_count' => 3,
            ],
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame(
            '12.1.01.05-0058',
            $decision->resources['materials'][0]['project_resource_selection']['selected_resource_code'],
        );
    }

    #[Test]
    public function accepts_a_semantically_selected_base_project_resource(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '09.4.03.01',
            'name' => 'Блоки оконные пластиковые',
            'price_source' => 'fsnb_base',
            'price_source_version' => '2026-05-07',
            'unit_price' => '11200.500000',
            'project_resource_selection' => [
                'group_code' => '09.4.03.01',
                'selected_resource_code' => '09.4.02.05-0042',
                'selected_resource_name' => 'Блок оконный из ПВХ профилей двухстворчатый',
                'price_source' => 'fsnb_base',
                'price_source_version' => '2026-05-07',
                'policy' => 'fsnb_semantic_hard_attributes_median:v4',
                'candidates_count' => 2,
            ],
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame(
            '09.4.02.05-0042',
            $decision->resources['materials'][0]['project_resource_selection']['selected_resource_code'],
        );
    }

    #[Test]
    public function accepts_an_exact_group_selection_filtered_by_hard_attributes(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '24.3.02.05',
            'name' => 'Трубы напорные многослойные из полипропилена диаметром 20 мм',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'prices-2026.06',
            'unit_price' => '145.500000',
            'project_resource_selection' => [
                'group_code' => '24.3.02.05',
                'selected_resource_code' => '24.3.02.05-0002',
                'selected_resource_name' => 'Труба напорная многослойная из полипропилена диаметром 20 мм',
                'price_source' => 'regional_catalog',
                'price_source_version' => 'prices-2026.06',
                'policy' => 'regional_child_hard_attributes_median:v2',
                'candidates_count' => 1,
            ],
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame(
            'regional_child_hard_attributes_median:v2',
            $decision->resources['materials'][0]['project_resource_selection']['policy'],
        );
    }

    #[Test]
    public function rejects_exact_group_policy_that_does_not_match_price_source(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '24.3.02.05',
            'price_source' => 'fsbc_base',
            'price_source_version' => 'fsbc-2026',
            'project_resource_selection' => [
                'group_code' => '24.3.02.05',
                'selected_resource_code' => '24.3.02.05-0002',
                'selected_resource_name' => 'Труба из полипропилена диаметром 20 мм',
                'price_source' => 'fsbc_base',
                'price_source_version' => 'fsbc-2026',
                'policy' => 'regional_child_hard_attributes_median:v1',
                'candidates_count' => 1,
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_normative_project_resource_selection_invalid');
        AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);
    }

    #[Test]
    public function accepts_an_explicitly_marked_project_resource_selection_from_the_fsbc_base_catalog(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0] = [
            ...$record['resources']['materials'][0],
            'code' => '04.1.02.05',
            'price_source' => 'fsbc_base',
            'price_source_version' => 'fsbc-2026',
            'project_resource_selection' => [
                'group_code' => '04.1.02.05',
                'selected_resource_code' => '04.1.02.05-0123',
                'selected_resource_name' => 'Бетон В25',
                'price_source' => 'fsbc_base',
                'price_source_version' => 'fsbc-2026',
                'policy' => 'fsbc_base_child_median:v1',
                'candidates_count' => 1,
            ],
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame('fsbc_base', $decision->resources['materials'][0]['project_resource_selection']['price_source']);
        self::assertSame('fsbc_base_child_median:v1', $decision->resources['materials'][0]['project_resource_selection']['policy']);
    }

    #[Test]
    public function rejects_cross_dataset_catalog_records(): void
    {
        $record = $this->catalogCandidate();
        $record['dataset_version'] = 'fsnb-2025.4';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_normative_dataset_mismatch');
        AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);
    }

    #[Test]
    public function accepts_zero_quantity_service_rows_that_are_part_of_the_authoritative_norm_set(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['other'][] = [
            'code' => '2', 'name' => 'Summary', 'unit' => 'h', 'quantity' => 0,
            'price_id' => 9002, 'price_source' => 'regional_catalog', 'linked_resource_id' => null,
        ];

        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);

        self::assertSame(0, $decision->resources['other'][0]['quantity']);
    }

    #[Test]
    public function rejects_a_normative_resource_set_without_a_positive_cost_contribution(): void
    {
        $record = $this->catalogCandidate();
        $record['resources']['materials'][0]['quantity'] = 0;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_normative_resources_invalid');
        AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);
    }

    #[Test]
    public function rejects_unit_mismatch_and_missing_resources_fail_closed(): void
    {
        $record = $this->catalogCandidate();
        $record['unit'] = 'pcs';
        $record['resources'] = ['materials' => [], 'labor' => [], 'machinery' => [], 'other' => []];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_normative_unit_mismatch');
        AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $record);
    }

    #[Test]
    public function production_resource_assembly_consumes_the_closed_decision_without_inventing_prices(): void
    {
        $service = new ResourceAssemblyService(
            $this->createMock(EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService,
            new NormativeCandidatePresenter,
        );

        $regionalContext = ['dataset_id' => 77, 'dataset_version' => 'fsnb-2026.1', 'region_id' => 77,
            'price_zone_id' => 1, 'period_id' => 202606, 'price_version' => 'prices-2026.06',
            'estimate_regional_price_version_id' => 8];
        $item = $service->assembleFromDecision(
            ['key' => 'work-1', 'name' => 'Монтаж стены', 'unit' => 'm2', 'quantity' => '2', 'confidence' => 0.8],
            AcceptedNormativeDecisionData::fromWorkflowResult($this->workflow(), $this->catalogCandidate()),
            $regionalContext,
        );

        self::assertSame('matched', $item['normative_match']['status']);
        self::assertSame(9001, $item['materials'][0]['normative_ref']['price_id']);
        self::assertSame('pcs', $item['materials'][0]['price_unit']);
        self::assertSame(0.0, $item['materials'][0]['unit_price']);
        self::assertSame('prices-2026.06', $item['price_dataset']['version_key']);
        $priced = (new EstimatePricingService(new ResolveRegionalPrice(static fn (int $priceId): array => [
            'id' => $priceId, 'region_id' => 77, 'price_zone_id' => 1, 'period_id' => 202606,
            'regional_price_version_id' => 8, 'base_price' => '3.50', 'source_type' => 'fsbc', 'currency' => 'RUB',
        ])))->price([$item], $regionalContext)[0];
        self::assertSame('350.00', $priced['total_cost']);
        self::assertSame('estimate_resource_prices:9001', $priced['price_snapshot']['coefficients']['resource_evidence'][0]['source_reference']);
    }

    private function workflow(): NormativeWorkflowResultData
    {
        $candidate = new NormativeCandidateData(
            'candidate-101', 101, 77, 'fsnb-2026.1', 'parsed', '10-01-001-01', 'Монтаж стены',
            'm2', 'area', 'brick', 'masonry', 'wall', '10-01', 'residential', '77',
            new DateTimeImmutable('2026-01-01'), null, 0.91, null, 'lexical-v1', null, ['evidence:norm-101'],
        );

        return new NormativeWorkflowResultData('retrieval_only', new NormativeCandidateSetData(
            1, 2, 3, 'work-1', 'fsnb-2026.1', 'lexical-v1', null, [$candidate],
        ), null, []);
    }

    private function catalogCandidate(): array
    {
        return [
            'candidate_id' => 'candidate-101', 'normative_id' => 101, 'dataset_id' => 77,
            'dataset_version' => 'fsnb-2026.1', 'dataset_status' => 'parsed', 'code' => '10-01-001-01',
            'name' => 'Монтаж стены', 'unit' => 'm2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '10-01', 'name' => 'Стены'], 'work_composition' => ['Монтаж'],
            'resources' => ['materials' => [[
                'code' => '01.7.01', 'name' => 'Кирпич', 'unit' => 'pcs', 'quantity' => 50,
                'price_id' => 9001, 'price_source' => 'fsbc', 'linked_resource_id' => 501,
                'price_unit' => 'pcs',
            ]], 'labor' => [], 'machinery' => [], 'other' => []],
        ];
    }
}
