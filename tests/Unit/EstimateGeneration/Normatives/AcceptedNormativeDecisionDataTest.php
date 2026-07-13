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
    public function rejects_cross_dataset_catalog_records(): void
    {
        $record = $this->catalogCandidate();
        $record['dataset_version'] = 'fsnb-2025.4';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_normative_dataset_mismatch');
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
            ]], 'labor' => [], 'machinery' => [], 'other' => []],
        ];
    }
}
