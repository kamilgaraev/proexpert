<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineArtifactStoreTest extends TestCase
{
    #[Test]
    public function replay_reuses_reference_without_exposing_content_and_tenant_is_fenced(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $owner = $this->context(20);
        $foreign = $this->context(21);
        $data = ['analysis' => ['description' => 'Секретное описание']];
        $first = $store->write($owner, $definition, $data);
        $second = $store->write($owner, $definition, $data);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertStringNotContainsString('Секретное', json_encode($first->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame($data, $store->read($owner, $first));
        $this->expectException(DomainException::class);
        $store->read($foreign, $first);
    }

    #[Test]
    public function forged_digest_is_rejected(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $context = $this->context(20);
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $reference = $store->write($context, $definition, ['analysis' => []]);
        $forged = new PipelineArtifactReference($reference->kind, $reference->objectKey, 'sha256:'.str_repeat('0', 64), $reference->bytes);

        $this->expectException(DomainException::class);
        $store->read($context, $forged);
    }

    #[Test]
    public function prior_outputs_load_only_requested_typed_payload(): void
    {
        $loads = 0;
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $dependency = 'sha256:'.str_repeat('d', 64);
        $dependencies = [ProcessingStage::UnderstandDocuments->value => $dependency];
        $input = PipelineInputVersion::for($definition, 'sha256:'.str_repeat('a', 64), $dependencies);
        $output = PipelineStageOutput::create($definition, $input, $dependencies, new PipelineArtifactReference('memory_json_v1', 'memory/one', 'sha256:'.str_repeat('b', 64), 10));
        $prior = new PipelinePriorOutputs([$output->stage->value => $output], loader: static function () use (&$loads): array {
            $loads++;

            return ['analysis' => []];
        });

        self::assertSame(['analysis' => []], $prior->payload(ProcessingStage::UnderstandObject));
        self::assertSame(1, $loads);
    }

    #[Test]
    public function production_sized_residential_pricing_artifact_fits_the_bounded_store(): void
    {
        $store = new InMemoryPipelineArtifactStore;
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::ResolvePrices);
        $data = $this->productionSizedResidentialPricingPayload();
        $bytes = strlen(CanonicalPipelineJson::encode($data));

        self::assertGreaterThan(1_441_792, $bytes);
        self::assertLessThanOrEqual($definition->maxArtifactBytes, $bytes);

        $reference = $store->write($this->context(20), $definition, $data);

        self::assertSame($bytes, $reference->bytes);
        self::assertSame($data, $store->read($this->context(20), $reference));
    }

    private function productionSizedResidentialPricingPayload(): array
    {
        $resourceEvidence = [];
        $resources = [];
        for ($resourceIndex = 0; $resourceIndex < 10; $resourceIndex++) {
            $code = sprintf('01.7.15.02-%04d', $resourceIndex + 1);
            $selection = [
                'group_code' => '01.7.15.02',
                'selected_resource_code' => $code,
                'selected_resource_name' => str_repeat('Материал для жилого дома с подтверждённой ценой ', 4),
                'price_id' => 10_000 + $resourceIndex,
                'price_source' => 'fsnb_base',
                'price_source_version' => 'ФСНБ-2022 с изменениями 1–14',
                'policy' => 'fsnb_2022_residential_converted_child_median:v1',
                'source_price_unit' => 'т',
                'source_unit_price' => '7450.250000',
                'conversion_factor' => '0.001000',
                'conversion_assumption' => 'масса типового строительного изделия подтверждена каталогом',
                'applied_unit_price' => '7.450250',
            ];
            $resourceEvidence[] = [
                'region_id' => 16,
                'zone_id' => 3,
                'period_id' => 8,
                'version_id' => 11,
                'source_type' => 'fsnb_2022',
                'source_reference' => 'estimate_resource_prices:'.(10_000 + $resourceIndex),
                'base_amount' => '7.4503',
                'coefficients' => [
                    'quantity' => '18.000000',
                    'price_kind' => 'base_catalog_converted',
                    'source_unit_price' => '7450.2500',
                    'source_price_unit' => 'т',
                    'conversion_factor' => '0.001000',
                    'conversion_assumption' => 'масса типового строительного изделия подтверждена каталогом',
                    'selected_resource_code' => $code,
                ],
                'final_amount' => '134.11',
                'currency' => 'RUB',
                'captured_at' => '2026-07-20T13:34:58+00:00',
            ];
            $resources[] = [
                'key' => 'resource-'.$resourceIndex,
                'code' => $code,
                'name' => str_repeat('Материал для жилого дома с нормативным наименованием ', 4),
                'resource_type' => 'material',
                'unit' => 'шт',
                'price_unit' => 'шт',
                'quantity' => '18.000000',
                'quantity_per_unit' => '1.000000',
                'unit_price' => '7.4503',
                'total_price' => '134.11',
                'price_source' => 'fsnb_base',
                'price_source_version' => 'ФСНБ-2022 с изменениями 1–14',
                'source' => 'normative_rate_resource',
                'confidence' => 0.94,
                'project_resource_selection' => $selection,
                'normative_ref' => [
                    'norm_id' => 2000 + $resourceIndex,
                    'norm_code' => '08-03-593-06',
                    'norm_resource_id' => 3000 + $resourceIndex,
                    'resource_code' => '01.7.15.02',
                    'price_id' => 10_000 + $resourceIndex,
                    'project_resource_selection' => $selection,
                ],
            ];
        }

        $workItems = [];
        for ($itemIndex = 0; $itemIndex < 54; $itemIndex++) {
            $workItems[] = [
                'key' => 'residential-work-'.$itemIndex,
                'name' => str_repeat('Устройство конструкций и инженерных систем жилого дома ', 3),
                'item_type' => 'priced_work',
                'unit' => 'm2',
                'quantity' => '180.000000',
                'quantity_evidence' => [
                    'value' => 180.0,
                    'unit' => 'm2',
                    'source' => 'document_measurement',
                    'confidence' => 0.94,
                    'evidence_ids' => ['document:plan:floor-1', 'document:plan:floor-2'],
                    'formula' => '90 + 90',
                    'assumptions' => [],
                    'requires_review' => false,
                ],
                'materials' => $resources,
                'labor' => [],
                'machinery' => [],
                'other_resources' => [],
                'normative_rate_code' => '08-03-593-06',
                'normative_match' => [
                    'status' => 'matched',
                    'norm_id' => 2000 + $itemIndex,
                    'code' => '08-03-593-06',
                    'name' => str_repeat('Норма для жилого здания с логически совместимым составом работ ', 3),
                    'unit' => '100 шт',
                    'confidence' => 0.94,
                    'warnings' => [],
                    'project_resource_selections' => array_column($resources, 'project_resource_selection'),
                    'dataset_version' => [
                        'source_type' => 'fsnb_2022',
                        'version_key' => 'ФСНБ-2022-14',
                    ],
                    'decision' => [
                        'status' => 'accepted',
                        'reason' => 'Норма соответствует жилому разделу и назначению работы',
                    ],
                ],
                'work_cost' => '0.00',
                'materials_cost' => '1341.10',
                'machinery_cost' => '0.00',
                'labor_cost' => '0.00',
                'total_cost' => '1341.10',
                'pricing_status' => 'calculated',
                'pricing_blocker' => 'none',
                'pricing_finalized_at' => '2026-07-20T13:34:58+00:00',
                'price_snapshot' => [
                    'region_id' => 16,
                    'zone_id' => 3,
                    'period_id' => 8,
                    'version_id' => 11,
                    'source_type' => 'regional_resource_aggregate',
                    'source_reference' => 'sha256:'.str_repeat('a', 64),
                    'base_amount' => '1341.10',
                    'coefficients' => [
                        'work_cost' => '0.00',
                        'resource_evidence' => $resourceEvidence,
                    ],
                    'final_amount' => '1341.10',
                    'currency' => 'RUB',
                    'captured_at' => '2026-07-20T13:34:58+00:00',
                ],
            ];
        }

        return [
            'regional_context' => [
                'region_id' => 16,
                'price_zone_id' => 3,
                'period_id' => 8,
                'estimate_regional_price_version_id' => 11,
            ],
            'supplementary_materials' => [],
            'local_estimates' => [[
                'key' => 'residential-house',
                'name' => 'Жилой дом 180 м²',
                'sections' => [[
                    'key' => 'complete-house-scope',
                    'name' => 'Полный состав жилого дома',
                    'work_items' => $workItems,
                ]],
            ]],
        ];
    }

    private function context(int $organizationId): PipelineContext
    {
        $base = 'sha256:'.str_repeat('a', 64);

        return new PipelineContext(10, $organizationId, 30, 4, $base, 'generating', generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base);
    }
}
