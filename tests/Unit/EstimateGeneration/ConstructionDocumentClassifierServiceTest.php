<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\ConstructionDocumentClassifierService;
use PHPUnit\Framework\TestCase;

final class ConstructionDocumentClassifierServiceTest extends TestCase
{
    public function test_classifies_architectural_drawing_by_sheet_markers(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'АР-2 План первого этажа.pdf',
            mimeType: 'application/pdf',
            pageCount: 1,
            text: "Лист АР-2\nПлан 1 этажа\nМасштаб 1:100\nЭкспликация помещений"
        );

        self::assertSame('drawing_architecture', $classification['type']);
        self::assertGreaterThanOrEqual(0.75, $classification['confidence']);
        self::assertContains('architectural_marker', $classification['reasons']);
    }

    public function test_classifies_engineering_electrical_drawing(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'ЭОМ схема освещения.pdf',
            mimeType: 'application/pdf',
            pageCount: 2,
            text: "ЭОМ\nПлан освещения\nЩР-1\nКабель ВВГнг-LS"
        );

        self::assertSame('drawing_engineering_electrical', $classification['type']);
        self::assertContains('electrical_marker', $classification['reasons']);
    }

    public function test_classifies_specification_table(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'Спецификация оборудования.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            pageCount: 1,
            text: "Поз. Наименование Ед. Количество\n1 Светильник шт 42"
        );

        self::assertSame('specification', $classification['type']);
        self::assertContains('spreadsheet_extension', $classification['reasons']);
    }

    public function test_classifies_work_volume_statement_as_quantity_source_document(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'Ведомость объемов работ.pdf',
            mimeType: 'application/pdf',
            pageCount: 1,
            text: "Наименование работ Ед. изм. Количество\nОбратная засыпка пазух м3 42"
        );

        self::assertSame('work_volume_statement', $classification['type']);
        self::assertContains('work_volume_statement_marker', $classification['reasons']);
        self::assertContains('quantity_table_marker', $classification['reasons']);
    }

    public function test_classifies_uploaded_flat_plan_image_as_floor_plan(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'flat-plan.png',
            mimeType: 'image/png',
            pageCount: 1,
            text: "Планировка квартиры\nГостиная 46,52 м2\nКухня 9,99 м2\n8755 x 6190"
        );

        self::assertSame('floor_plan', $classification['type']);
        self::assertGreaterThanOrEqual(0.7, $classification['confidence']);
        self::assertContains('floor_plan_layout_marker', $classification['reasons']);
    }

    public function test_classifies_floor_plan_screenshot_by_geometry_density_without_filename_hint(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'scan-001.png',
            mimeType: 'image/png',
            pageCount: 1,
            text: "5.14 м²\n4.34 м²\n46.52 м²\n17.65 м²\n3255 1580 8755 14845 3355 5040"
        );

        self::assertSame('floor_plan', $classification['type']);
        self::assertGreaterThanOrEqual(0.7, $classification['confidence']);
        self::assertContains('floor_plan_geometry_marker', $classification['reasons']);
    }

    public function test_classifies_cad_drawings_by_extension(): void
    {
        foreach (['plan.dwg', 'АР-план.dwg'] as $filename) {
            $classification = (new ConstructionDocumentClassifierService())->classify(
                filename: $filename,
                mimeType: 'application/acad',
                pageCount: 1,
                text: ''
            );

            self::assertSame('drawing_cad', $classification['type']);
            self::assertContains('cad_extension', $classification['reasons']);
        }
    }

    public function test_classifies_grand_smeta_reference_estimate(): void
    {
        $classification = (new ConstructionDocumentClassifierService())->classify(
            filename: 'Локальная смета из Гранд-Сметы.pdf',
            mimeType: 'application/pdf',
            pageCount: 8,
            text: "Локальная смета\nГранд-Смета\nОбоснование ФЕР 08-02-001-01\nФСБЦ материалы"
        );

        self::assertSame('estimate', $classification['type']);
        self::assertContains('estimate_marker', $classification['reasons']);
    }
}
