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
}
