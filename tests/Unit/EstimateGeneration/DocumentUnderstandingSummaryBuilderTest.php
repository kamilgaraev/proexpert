<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\ConstructionDocumentClassifierService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentUnderstandingSummaryBuilder;
use PHPUnit\Framework\TestCase;

final class DocumentUnderstandingSummaryBuilderTest extends TestCase
{
    public function test_floor_plan_becomes_geometry_source_with_takeoff_capabilities(): void
    {
        $summary = $this->builder()->build(
            $this->document('flat-plan.png', 'image/png'),
            $this->recognition("Планировка квартиры\nГостиная 46,52 м2\nКухня 9,99 м2\n8755 x 6190"),
            [
                'source_format' => 'image',
                'takeoffs_count' => 5,
                'room_count' => 2,
                'dimension_count' => 1,
                'axis_count' => 4,
                'title_block_count' => 1,
                'document_profile' => [
                    'document_role' => 'floor_plan',
                    'source_format' => 'image',
                    'confidence' => 0.91,
                    'requires_manual_review' => false,
                ],
                'page_profiles' => [[
                    'page_number' => 1,
                    'page_role' => 'floor_plan',
                    'confidence' => 0.93,
                    'signals' => ['plan_keywords', 'room_areas', 'dimensions', 'axes', 'title_block'],
                ]],
            ],
            []
        );

        self::assertSame('floor_plan', $summary['document_type']);
        self::assertSame('geometry_source', $summary['role_for_estimation']);
        self::assertSame('image', $summary['source_format']);
        self::assertTrue($summary['extracted_capabilities']['has_room_areas']);
        self::assertTrue($summary['extracted_capabilities']['has_dimensions']);
        self::assertTrue($summary['extracted_capabilities']['has_axes']);
        self::assertTrue($summary['extracted_capabilities']['has_title_block']);
        self::assertTrue($summary['extracted_capabilities']['has_quantity_takeoffs']);
        self::assertFalse($summary['extracted_capabilities']['requires_manual_review']);
        self::assertContains('has_axes', $summary['signals']);
        self::assertContains('has_title_block', $summary['signals']);
        self::assertSame('floor_plan', $this->builder()->pageUnderstandingByNumber($summary)[1]['page_role']);
    }

    public function test_geometry_dense_floor_plan_screenshot_becomes_geometry_source_without_filename_hint(): void
    {
        $summary = $this->builder()->build(
            $this->document('scan-001.png', 'image/png'),
            $this->recognition("5.14 м²\n4.34 м²\n46.52 м²\n17.65 м²\n3255 1580 8755 14845 3355 5040"),
            [
                'source_format' => 'image',
                'takeoffs_count' => 4,
                'room_count' => 4,
                'dimension_count' => 6,
                'page_profiles' => [[
                    'page_number' => 1,
                    'page_role' => 'floor_plan',
                    'confidence' => 0.86,
                    'signals' => ['room_areas', 'dimensions'],
                ]],
            ],
            []
        );

        self::assertSame('floor_plan', $summary['document_type']);
        self::assertSame('floor_plan', $summary['classified_type']);
        self::assertSame('geometry_source', $summary['role_for_estimation']);
        self::assertContains('floor_plan_geometry_marker', $summary['reasons']);
        self::assertTrue($summary['extracted_capabilities']['has_room_areas']);
        self::assertTrue($summary['extracted_capabilities']['has_dimensions']);
    }

    public function test_specification_spreadsheet_becomes_quantity_source(): void
    {
        $summary = $this->builder()->build(
            $this->document('Спецификация оборудования.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            $this->recognition("Поз. Наименование Ед. Количество\n1 Светильник шт 42"),
            [],
            []
        );

        self::assertSame('specification', $summary['document_type']);
        self::assertSame('quantity_source', $summary['role_for_estimation']);
        self::assertSame('spreadsheet', $summary['source_format']);
        self::assertTrue($summary['extracted_capabilities']['has_specification_markers']);
    }

    public function test_work_volume_statement_becomes_quantity_source(): void
    {
        $summary = $this->builder()->build(
            $this->document('Ведомость объемов работ.pdf', 'application/pdf'),
            $this->recognition("Ведомость объемов работ\nНаименование работ Ед. изм. Количество\nОбратная засыпка пазух м3 42"),
            [
                'takeoffs_count' => 1,
                'document_profile' => [
                    'document_role' => 'work_volume_statement',
                    'confidence' => 0.91,
                    'requires_manual_review' => false,
                ],
                'page_profiles' => [[
                    'page_number' => 1,
                    'page_role' => 'work_volume_statement',
                    'confidence' => 0.91,
                    'signals' => ['work_volume_statement_keywords', 'work_volume_statement_quantities'],
                ]],
            ],
            []
        );

        self::assertSame('work_volume_statement', $summary['document_type']);
        self::assertSame('work_volume_statement', $summary['classified_type']);
        self::assertSame('quantity_source', $summary['role_for_estimation']);
        self::assertTrue($summary['extracted_capabilities']['has_work_volume_statement_markers']);
        self::assertSame('quantity_source', $this->builder()->pageUnderstandingByNumber($summary)[1]['role_for_estimation']);
    }

    public function test_work_volume_statement_with_normative_basis_stays_quantity_source(): void
    {
        $summary = $this->builder()->build(
            $this->document('Ведомость объемов работ.pdf', 'application/pdf'),
            $this->recognition("Ведомость объемов работ\nНаименование работ Ед. изм. Количество Обоснование\nБетонирование фундаментов м3 12,5 ФЕР 06-01-001-01"),
            [
                'takeoffs_count' => 1,
                'document_profile' => [
                    'document_role' => 'work_volume_statement',
                    'confidence' => 0.91,
                    'requires_manual_review' => false,
                ],
                'page_profiles' => [[
                    'page_number' => 1,
                    'page_role' => 'work_volume_statement',
                    'confidence' => 0.91,
                    'signals' => ['work_volume_statement_keywords', 'work_volume_statement_quantities', 'estimate_or_norm_keywords'],
                ]],
            ],
            []
        );

        self::assertSame('work_volume_statement', $summary['document_type']);
        self::assertSame('work_volume_statement', $summary['classified_type']);
        self::assertSame('quantity_source', $summary['role_for_estimation']);
        self::assertTrue($summary['extracted_capabilities']['has_estimate_markers']);
        self::assertTrue($summary['extracted_capabilities']['has_work_volume_statement_markers']);
    }

    public function test_grand_smeta_reference_estimate_becomes_reference_estimate(): void
    {
        $summary = $this->builder()->build(
            $this->document('Локальная смета из Гранд-Сметы.pdf', 'application/pdf'),
            $this->recognition("Локальная смета\nГранд-Смета\nОбоснование ФЕР 08-02-001-01\nФСБЦ материалы"),
            [
                'takeoffs_count' => 2,
                'page_profiles' => [[
                    'page_number' => 1,
                    'page_role' => 'reference_estimate',
                    'confidence' => 0.92,
                    'signals' => ['estimate_or_norm_keywords', 'quantity_table'],
                ]],
            ],
            []
        );

        self::assertSame('estimate', $summary['classified_type']);
        self::assertSame('reference_estimate', $summary['role_for_estimation']);
        self::assertTrue($summary['extracted_capabilities']['has_estimate_markers']);
    }

    public function test_strong_estimate_marker_wins_over_wrong_quantity_document_profile(): void
    {
        $summary = $this->builder()->build(
            $this->document('Локальная смета из Гранд-Сметы.pdf', 'application/pdf'),
            $this->recognition("Локальная смета\nГранд-Смета\nНаименование работ Ед. изм. Количество\nОбоснование ФЕР 08-02-001-01"),
            [
                'takeoffs_count' => 1,
                'document_profile' => [
                    'document_role' => 'work_volume_statement',
                    'confidence' => 0.82,
                    'requires_manual_review' => false,
                ],
                'page_profiles' => [[
                    'page_number' => 1,
                    'page_role' => 'work_volume_statement',
                    'confidence' => 0.82,
                    'signals' => ['work_volume_statement_keywords', 'estimate_or_norm_keywords'],
                ]],
            ],
            []
        );

        self::assertSame('work_volume_statement', $summary['document_type']);
        self::assertSame('estimate', $summary['classified_type']);
        self::assertSame('reference_estimate', $summary['role_for_estimation']);
        self::assertTrue($summary['extracted_capabilities']['has_strong_estimate_markers']);
    }

    public function test_cad_without_takeoffs_requires_geometry_pipeline_and_manual_review(): void
    {
        $summary = $this->builder()->build(
            $this->document('АР-план.dwg', 'application/acad'),
            $this->recognition(''),
            [],
            []
        );

        self::assertSame('drawing_cad', $summary['classified_type']);
        self::assertSame('needs_review', $summary['role_for_estimation']);
        self::assertSame('cad', $summary['source_format']);
        self::assertTrue($summary['extracted_capabilities']['requires_cad_geometry_pipeline']);
        self::assertTrue($summary['extracted_capabilities']['requires_manual_review']);
    }

    public function test_uncertain_floor_plan_requires_review_before_generation(): void
    {
        $summary = $this->builder()->build(
            $this->document('Планировка.jpg', 'image/jpeg'),
            $this->recognition("Планировка квартиры\nГостиная 46,52 м2\nКухня 9,99 м2"),
            [
                'source_format' => 'image',
                'takeoffs_count' => 2,
                'room_count' => 2,
                'document_profile' => [
                    'document_role' => 'floor_plan',
                    'confidence' => 0.54,
                    'requires_manual_review' => true,
                ],
            ],
            []
        );

        self::assertSame('floor_plan', $summary['document_type']);
        self::assertSame('needs_review', $summary['role_for_estimation']);
        self::assertTrue($summary['extracted_capabilities']['requires_manual_review']);
    }

    private function builder(): DocumentUnderstandingSummaryBuilder
    {
        return new DocumentUnderstandingSummaryBuilder(new ConstructionDocumentClassifierService());
    }

    private function document(string $filename, string $mimeType): EstimateGenerationDocument
    {
        $document = new EstimateGenerationDocument();
        $document->forceFill([
            'filename' => $filename,
            'mime_type' => $mimeType,
        ]);

        return $document;
    }

    private function recognition(string $text): OcrRecognitionResult
    {
        return new OcrRecognitionResult(
            provider: 'test',
            model: 'unit',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: $text,
                    confidence: 0.91
                ),
            ]
        );
    }
}
