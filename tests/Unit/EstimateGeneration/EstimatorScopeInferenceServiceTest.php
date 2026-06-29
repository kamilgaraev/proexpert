<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EstimatorScopeInferenceServiceTest extends TestCase
{
    public function test_infers_scope_from_specification_quantity_takeoff(): void
    {
        $inferences = (new EstimatorScopeInferenceService())->inferFromDocumentPayload([
            'id' => 12,
            'filename' => 'spec.xlsx',
            'quantity_takeoffs' => [[
                'scope_key' => 'specification_quantity',
                'name' => 'Светильник светодиодный',
                'unit' => 'шт',
                'quantity' => 42,
                'source_refs' => [[
                    'type' => 'document',
                    'document_id' => 12,
                    'filename' => 'spec.xlsx',
                    'page_number' => 1,
                ]],
                'normalized_payload' => [
                    'quantity_key' => 'warehouse.lighting',
                    'source' => 'specification',
                ],
            ]],
        ]);

        self::assertCount(1, $inferences);
        self::assertSame('specification_takeoff', $inferences[0]['inference_type']);
        self::assertSame('electrical', $inferences[0]['scope_type']);
        self::assertSame('warehouse.lighting', $inferences[0]['normalized_payload']['quantity_key']);
        self::assertSame(42, $inferences[0]['normalized_payload']['quantity_value']);
    }

    public function test_uses_source_takeoff_name_as_specification_inference_title(): void
    {
        $inferences = (new EstimatorScopeInferenceService())->inferFromDocumentPayload([
            'id' => 12,
            'filename' => 'spec.xlsx',
            'quantity_takeoffs' => [[
                'scope_key' => 'specification_quantity',
                'name' => 'Radiator steel panel 22 500x1000',
                'unit' => 'pcs',
                'quantity' => 8,
                'source_refs' => [[
                    'type' => 'document',
                    'document_id' => 12,
                    'filename' => 'spec.xlsx',
                    'page_number' => 1,
                ]],
                'normalized_payload' => [
                    'quantity_key' => 'heating.radiators',
                    'scope_type' => 'heating',
                    'source' => 'specification',
                ],
            ]],
        ]);

        self::assertCount(1, $inferences);
        self::assertSame('specification_takeoff', $inferences[0]['inference_type']);
        self::assertSame('heating', $inferences[0]['scope_type']);
        self::assertSame('Radiator steel panel 22 500x1000', $inferences[0]['title']);
    }

    public function test_infers_work_volume_statement_scope_from_takeoff_payload(): void
    {
        $inferences = (new EstimatorScopeInferenceService())->inferFromDocumentPayload([
            'id' => 12,
            'filename' => 'Ведомость объемов работ.pdf',
            'quantity_takeoffs' => [[
                'scope_key' => 'specification_quantity',
                'name' => 'Обратная засыпка пазух',
                'unit' => 'м3',
                'quantity' => 42,
                'work_intent' => ['scope' => 'earthworks', 'basis' => 'specification_row'],
                'source_refs' => [[
                    'type' => 'drawing',
                    'document_id' => 12,
                    'filename' => 'Ведомость объемов работ.pdf',
                    'page_number' => 1,
                ]],
                'normalized_payload' => [
                    'quantity_key' => 'earth.backfill',
                    'scope_type' => 'earthworks',
                    'source' => 'work_volume_statement',
                ],
            ]],
        ]);

        self::assertCount(1, $inferences);
        self::assertSame('work_volume_takeoff', $inferences[0]['inference_type']);
        self::assertSame('earthworks', $inferences[0]['scope_type']);
        self::assertSame('Обратная засыпка пазух', $inferences[0]['title']);
        self::assertSame('work_volume_statement', $inferences[0]['normalized_payload']['source']);
    }

    public function test_normalizes_persisted_scope_inference_shape_for_planner(): void
    {
        $inferences = (new EstimatorScopeInferenceService())->inferFromAnalysis([
            'document_context' => [
                'scope_inferences' => [[
                    'inference_type' => 'work_volume_takeoff',
                    'title' => 'Земляные работы',
                    'source_refs' => [[
                        'type' => 'drawing',
                        'document_id' => 12,
                        'filename' => 'Ведомость объемов работ.pdf',
                        'page_number' => 1,
                    ]],
                    'work_intent' => [
                        'scope_type' => 'earthworks',
                        'quantity_key' => 'earth.backfill',
                        'source' => 'work_volume_statement',
                    ],
                    'normative_basis' => [
                        'quantity_key' => 'earth.backfill',
                        'quantity_value' => 42.0,
                        'unit' => 'м3',
                        'source' => 'work_volume_statement',
                    ],
                    'confidence' => 0.84,
                    'review_required' => false,
                ]],
            ],
        ]);

        self::assertCount(1, $inferences);
        self::assertSame('earthworks', $inferences[0]['scope_type']);
        self::assertSame('earth.backfill', $inferences[0]['normalized_payload']['quantity_key']);
        self::assertSame(42.0, $inferences[0]['normalized_payload']['quantity_value']);
        self::assertSame('м3', $inferences[0]['normalized_payload']['unit']);
        self::assertSame('work_volume_statement', $inferences[0]['normalized_payload']['source']);
        self::assertSame('drawing', $inferences[0]['source_ref']['type']);
    }

    public function test_scope_inference_persistence_attributes_match_model_contract(): void
    {
        $service = new EstimatorScopeInferenceService();
        $document = new EstimateGenerationDocument();
        $document->forceFill([
            'id' => 12,
            'organization_id' => 7,
            'project_id' => 8,
            'session_id' => 9,
        ]);
        $method = new ReflectionMethod(EstimatorScopeInferenceService::class, 'scopeInferenceAttributes');
        $method->setAccessible(true);

        $attributes = $method->invoke($service, $document, [
            'inference_type' => 'specification_takeoff',
            'scope_type' => 'earthworks',
            'title' => 'Земляные работы',
            'confidence' => 0.84,
            'review_required' => false,
            'source_ref' => [
                'type' => 'drawing',
                'document_id' => 12,
                'filename' => 'Ведомость объемов работ.pdf',
                'page_number' => 1,
            ],
            'normalized_payload' => [
                'quantity_key' => 'earth.backfill',
                'quantity_value' => 42.0,
                'unit' => 'м3',
                'source' => 'work_volume_statement',
            ],
        ]);

        self::assertArrayNotHasKey('scope_type', $attributes);
        self::assertArrayNotHasKey('source_ref', $attributes);
        self::assertArrayNotHasKey('normalized_payload', $attributes);
        self::assertSame([[
            'type' => 'drawing',
            'document_id' => 12,
            'filename' => 'Ведомость объемов работ.pdf',
            'page_number' => 1,
        ]], $attributes['source_refs']);
        self::assertSame('earthworks', $attributes['work_intent']['scope']);
        self::assertSame('earth.backfill', $attributes['work_intent']['quantity_key']);
        self::assertSame('work_volume_statement', $attributes['work_intent']['source']);
        self::assertSame('earth.backfill', $attributes['normative_basis']['quantity_key']);
        self::assertSame(42.0, $attributes['normative_basis']['quantity_value']);
        self::assertSame('м3', $attributes['normative_basis']['unit']);
    }

    public function test_infer_from_analysis_normalizes_persisted_scope_inference_shape(): void
    {
        $inferences = (new EstimatorScopeInferenceService())->inferFromAnalysis([
            'document_context' => [
                'scope_inferences' => [[
                    'inference_type' => 'specification_takeoff',
                    'title' => 'Земляные работы',
                    'source_refs' => [[
                        'type' => 'drawing',
                        'document_id' => 12,
                        'filename' => 'Ведомость объемов работ.pdf',
                        'page_number' => 1,
                    ]],
                    'work_intent' => [
                        'scope' => 'earthworks',
                        'quantity_key' => 'earth.backfill',
                        'source' => 'work_volume_statement',
                    ],
                    'normative_basis' => [
                        'quantity_value' => 42.0,
                        'unit' => 'м3',
                    ],
                    'confidence' => 0.84,
                    'review_required' => false,
                ]],
            ],
        ]);

        self::assertCount(1, $inferences);
        self::assertSame('earthworks', $inferences[0]['scope_type']);
        self::assertSame('earth.backfill', $inferences[0]['normalized_payload']['quantity_key']);
        self::assertSame(42.0, $inferences[0]['normalized_payload']['quantity_value']);
        self::assertSame('м3', $inferences[0]['normalized_payload']['unit']);
        self::assertSame('work_volume_statement', $inferences[0]['normalized_payload']['source']);
        self::assertSame(12, $inferences[0]['source_ref']['document_id']);
    }
}
