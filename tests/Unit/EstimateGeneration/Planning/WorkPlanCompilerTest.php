<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedWorkPlannerResponseData;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\TestCase;

final class WorkPlanCompilerTest extends TestCase
{
    public function test_runtime_compilation_is_identical_to_legacy_algorithm(): void
    {
        $analysis = $this->analysis();
        $packagePlanner = new PackagePlannerService;
        $decomposition = new EstimateDecompositionService;
        $workPlanner = new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
        );
        $pins = new NormativeContextPinResolver;
        $profile = $packagePlanner->profileFromAnalysis($analysis);
        $plan = $packagePlanner->plan($profile);
        $localEstimates = $decomposition->decomposePackagePlan($analysis, $plan);
        foreach ($localEstimates as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $workPlanner->build($localEstimate, $section, $analysis);
            }
        }
        $expected = [
            'object_profile' => $profile->toArray(),
            'package_plan' => $plan->toArray(),
            'document_requirements' => $packagePlanner->documentRequirements($profile),
            'generation_mode' => EstimateGenerationMode::fromInput($profile->planningSignals['generation_mode'] ?? null)->value,
            'regional_context' => $analysis['regional_context'],
            'normative_context_pin' => $pins->resolve($analysis['regional_context']),
            'local_estimates' => $localEstimates,
        ];

        $actual = (new WorkPlanCompiler($packagePlanner, $decomposition, $workPlanner, $pins))->compile($analysis);

        self::assertSame($expected, $actual);
        self::assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)));
    }

    public function test_recorded_response_supplies_semantic_intents_without_final_norms_or_prices(): void
    {
        $source = RecordedWorkPlannerResponseData::fromProviderArray([
            'schema_version' => 'work-planner-v1',
            'sections' => [[
                'section_key' => 'foundation-section-1',
                'title' => 'Фундамент',
                'scope_type' => 'foundation',
                'source_refs' => ['quantity:q1'],
                'work_intents' => [[
                    'intent_key' => 'foundation.concrete',
                    'name' => 'Устройство монолитного фундамента',
                    'category' => 'foundation',
                    'unit' => 'm3',
                    'quantity' => '12.5',
                    'quantity_key' => 'concrete_volume', 'quantity_source_refs' => ['quantity:q1'],
                    'confidence' => 0.91,
                    'work_intent' => ['material' => 'concrete', 'action' => 'concreting', 'scope' => 'foundation',
                        'object' => 'foundation', 'dimensions' => ['volume'], 'preferred_section_prefixes' => ['06']],
                ]],
            ]],
        ])->toWorkPlannerResponse();

        $payload = $this->compiler()->compile($this->analysis(), $source);
        $items = array_merge(...array_map(static fn (array $estimate): array => array_merge(...array_column($estimate['sections'], 'work_items')), $payload['local_estimates']));
        $item = collect($items)->firstWhere('key', 'foundation.concrete');

        self::assertIsArray($item);
        self::assertSame('12.5', $item['quantity']);
        self::assertSame('not_calculated', $item['pricing_status']);
        self::assertNull($item['normative_rate_code']);
        self::assertSame('concreting', $item['work_intent']['action']);
        self::assertArrayNotHasKey('norm_id', $item);
    }

    public function test_normative_pin_is_resolved_from_final_canonical_work_item_units(): void
    {
        $pins = $this->createMock(NormativeContextPinResolver::class);
        $pins->expects(self::once())
            ->method('resolve')
            ->with([], [[
                'search_text' => 'Устройство полов',
                'unit' => 'm2',
                'code' => null,
                'normative_section' => '11',
            ]])
            ->willReturn(['status' => 'pinned']);
        $compiler = new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            $pins,
        );

        $pin = $compiler->resolveNormativeContextPin([], [[
            'sections' => [[
                'work_items' => [[
                    'item_type' => 'priced_work',
                    'name' => 'Устройство полов',
                    'normative_search_text' => 'Устройство полов',
                    'unit' => 'm2',
                    'normative_rate_code' => null,
                    'work_intent' => ['preferred_section_prefixes' => ['11']],
                ]],
            ]],
        ]]);

        self::assertSame(['status' => 'pinned'], $pin);
    }

    private function compiler(): WorkPlanCompiler
    {
        return new WorkPlanCompiler(
            new PackagePlannerService,
            new EstimateDecompositionService,
            new NormativeWorkItemPlannerService(new ProjectDocumentNormativeReferenceExtractor, new EstimatorScopeInferenceService),
            new NormativeContextPinResolver,
        );
    }

    private function analysis(): array
    {
        return [
            'object' => ['description' => 'Монолитный фундамент', 'area' => 100],
            'detected_structure' => ['scopes' => [['scope_type' => 'foundation', 'title' => 'Фундамент', 'source_refs' => []]]],
            'document_context' => ['quantity_model' => ['quantities' => ['concrete_volume' => ['value' => 12.5, 'unit' => 'm3', 'source' => 'document', 'source_refs' => []]]]],
            'regional_context' => [],
            'planning_signals' => ['generation_mode' => 'strict'],
        ];
    }
}
