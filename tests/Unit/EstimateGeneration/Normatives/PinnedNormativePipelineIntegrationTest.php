<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeWorkflowResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PinnedNormativePipelineIntegrationTest extends TestCase
{
    #[Test]
    public function compiler_pin_selected_catalog_content_assembly_and_pricing_use_one_exact_context(): void
    {
        $catalog = $this->catalogCandidate();
        $source = new class($catalog) implements NormativeContextPinSource
        {
            public function __construct(private array $catalog) {}

            public function resolve(NormativeContextPinData $requested): ?NormativeContextPinData
            {
                return new NormativeContextPinData(
                    $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                    $requested->regionId, $requested->priceZoneId, $requested->periodId,
                    $requested->regionalPriceVersionId, $requested->priceVersion,
                    [$this->catalog], hash('sha256', json_encode([$this->catalog], JSON_THROW_ON_ERROR)),
                );
            }
        };
        $resolver = new NormativeContextPinResolver($source);
        $regional = [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-13',
        ];
        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            $resolver,
        );
        $plan = $compiler->compile([
            'object' => ['description' => 'Монтаж стены', 'area' => 12],
            'detected_structure' => ['scopes' => [['scope_type' => 'walls', 'title' => 'Стены', 'source_refs' => []]]],
            'document_context' => [], 'planning_signals' => ['generation_mode' => 'strict'],
            'regional_context' => $regional,
        ]);
        $pin = $plan['normative_context_pin'];
        $candidate = new NormativeCandidateData(
            '101', 101, 77, 'fsnb-2026.1', 'parsed', '10-01-001-01', 'Монтаж стены',
            'm2', 'area', 'brick', 'masonry', 'wall', '10-01', 'residential', '16',
            new DateTimeImmutable('2026-01-01'), null, 1.0, null, 'lexical-v1', null, ['norm:101'],
        );
        $workflow = new NormativeWorkflowResultData('retrieval_only', new NormativeCandidateSetData(
            1, 2, 3, 'work-1', 'fsnb-2026.1', 'lexical-v1', null, [$candidate],
        ), null, []);
        $assembly = new ResourceAssemblyService(
            $this->createMock(EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService,
            new NormativeCandidatePresenter,
        );
        $item = $assembly->assembleFromDecision(
            ['key' => 'work-1', 'name' => 'Монтаж стены', 'unit' => 'm2', 'quantity' => '2', 'confidence' => 1.0],
            AcceptedNormativeDecisionData::fromWorkflowResult($workflow, $pin['catalog_candidates'][0]),
            $pin['regional_context'],
        );
        $priced = (new EstimatePricingService(new ResolveRegionalPrice(static fn (int $id): array => [
            'id' => $id, 'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'regional_price_version_id' => 11, 'base_price' => '3.50', 'source_type' => 'regional_catalog',
        ])))->price([$item], $pin['regional_context'])[0];

        self::assertSame('pinned', $pin['status']);
        self::assertSame(101, $item['normative_match']['norm_id']);
        self::assertSame(9001, $item['materials'][0]['normative_ref']['price_id']);
        self::assertSame('350.00', $priced['total_cost']);
        self::assertSame(11, $priced['price_snapshot']['version_id']);
    }

    private function catalogCandidate(): array
    {
        return [
            'candidate_id' => '101', 'normative_id' => 101, 'dataset_id' => 77,
            'dataset_version' => 'fsnb-2026.1', 'dataset_status' => 'parsed', 'code' => '10-01-001-01',
            'name' => 'Монтаж стены', 'unit' => 'm2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '10-01', 'name' => 'Стены'], 'work_composition' => ['Монтаж'],
            'resources' => ['materials' => [[
                'code' => '01.7.01', 'name' => 'Кирпич', 'unit' => 'pcs', 'quantity' => 50,
                'price_id' => 9001, 'price_source' => 'regional_catalog', 'linked_resource_id' => 501,
            ]], 'labor' => [], 'machinery' => [], 'other' => []],
        ];
    }
}
