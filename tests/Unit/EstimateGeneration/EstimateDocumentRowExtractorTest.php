<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\EstimateDocumentRowExtractor;
use PHPUnit\Framework\TestCase;

final class EstimateDocumentRowExtractorTest extends TestCase
{
    public function test_extracts_work_norm_row_with_quantity(): void
    {
        $rows = $this->extractor()->extractFromText('ФЕР 08-02-001-01 Кладка стен 10 м2');

        self::assertCount(1, $rows);
        self::assertSame('work_norm', $rows[0]['code_kind']);
        self::assertSame('ФЕР', $rows[0]['code_prefix']);
        self::assertSame('08-02-001-01', $rows[0]['code']);
        self::assertSame('Кладка стен', $rows[0]['name']);
        self::assertSame(10.0, $rows[0]['quantity']);
        self::assertSame('м2', $rows[0]['unit']);
        self::assertSame('project_document', $rows[0]['quantity_source']);
    }

    public function test_extracts_fsbc_resource_row_without_work_norm(): void
    {
        $rows = $this->extractor()->extractFromText('ФСБЦ 01.1.01.01-0001 Бетон тяжелый 12 м3');

        self::assertCount(1, $rows);
        self::assertSame('fsbc_resource', $rows[0]['code_kind']);
        self::assertSame('ФСБЦ', $rows[0]['code_prefix']);
        self::assertSame('01.1.01.01-0001', $rows[0]['code']);
        self::assertSame('Бетон тяжелый', $rows[0]['name']);
        self::assertSame(12.0, $rows[0]['quantity']);
        self::assertSame('м3', $rows[0]['unit']);
    }

    public function test_ignores_row_without_explicit_normative_code(): void
    {
        self::assertSame([], $this->extractor()->extractFromText('Кладка стен 10 м2'));
    }

    public function test_normative_code_never_supplies_a_missing_document_quantity(): void
    {
        foreach ([
            'ФЕР08-02-001-01 Монтаж окон' => 'компл',
            'ФЕР08-02-001-01 м2' => 'м2',
            'ФЕР 08-02-001-10 Прокладка труб м' => 'м',
            'ФСБЦ 01.1.01.01-0001 м3' => 'м3',
            'ФЕР08-02-001-01 ФЕР08-02-001-10 м2' => 'м2',
        ] as $line => $unit) {
            $row = $this->extractor()->extractFromLine($line);
            self::assertIsArray($row, $line);
            self::assertNull($row['quantity'], $line);
            self::assertSame($unit, $row['unit']);
            self::assertSame('review', $row['quantity_source']);
            self::assertSame($line, $row['line']);
        }
    }

    public function test_measured_quantity_after_code_and_bare_unit_is_still_extracted(): void
    {
        foreach ([
            'ФЕР08-02-001-01 м2 Монтаж покрытия 12 м2' => 12.0,
            'ФСБЦ 01.1.01.01-0001 Бетон 2,5 м3' => 2.5,
        ] as $line => $quantity) {
            $row = $this->extractor()->extractFromLine($line);
            self::assertSame($quantity, $row['quantity']);
            self::assertSame('project_document', $row['quantity_source']);
        }
    }

    private function extractor(): EstimateDocumentRowExtractor
    {
        return new EstimateDocumentRowExtractor;
    }
}
