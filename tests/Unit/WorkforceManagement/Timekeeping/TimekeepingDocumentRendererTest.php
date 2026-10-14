<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingDocumentRenderer;
use PHPUnit\Framework\TestCase;

final class TimekeepingDocumentRendererTest extends TestCase
{
    public function test_csv_has_frozen_revision_digest_paper_signatures_and_no_executable_formula(): void
    {
        $result = (new TimekeepingDocumentRenderer)->render($this->period(), 'csv', '=HYPERLINK("evil")');
        self::assertStringContainsString("'=HYPERLINK", $result['body']);
        self::assertStringContainsString(str_repeat('a', 64), $result['body']);
        self::assertStringContainsString('Бумажная подпись', $result['body']);
        self::assertStringContainsString('Номер / версия', $result['body']);
        self::assertStringContainsString('Проверен', $result['body']);
        self::assertStringNotContainsString(';verified', $result['body']);
        self::assertStringContainsString('тип неявки требует сверки', $result['body']);
    }

    public function test_xlsx_is_real_archive_and_formula_like_person_names_are_strings(): void
    {
        $result = (new TimekeepingDocumentRenderer)->render($this->period(), 'xlsx', '=1+1');
        self::assertStringStartsWith('PK', $result['body']);
        $path = tempnam(sys_get_temp_dir(), 'most-timekeeping-render-');
        try {
            file_put_contents($path, $result['body']);
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            self::assertIsString($xml);
            self::assertStringNotContainsString('<f>', $xml);
        } finally {
            unlink($path);
        }
    }

    public function test_pdf_renders_real_pdf_with_embedded_cyrillic_font(): void
    {
        $result = (new TimekeepingDocumentRenderer)->render($this->period(), 'pdf', 'ООО МОСТ');
        self::assertSame('application/pdf', $result['mime']);
        self::assertStringStartsWith('%PDF-', $result['body']);
        self::assertGreaterThan(1000, strlen($result['body']));
    }

    private function period(): array
    {
        return ['id' => 1, 'revision' => 2, 'status' => 'verified', 'snapshot_sha256' => str_repeat('a', 64), 'rule_snapshot' => ['form' => ['version' => 'MOST-TIME-1', 'approved_basis_reference' => 'Приказ1']], 'snapshot' => ['month' => '2026-09', 'rows' => [['personnel_number' => '01', 'full_name' => '=1+1', 'contract_number' => 'C-1', 'relation_type' => 'employment', 'days' => [['planned_minutes' => 480, 'norm_minutes' => 480, 'fact_minutes' => 480, 'night_minutes' => 0, 'weekend_minutes' => 0, 'overtime_minutes' => 0, 'absence_code' => null]], 'summary' => ['fact_minutes' => 480]]]]];
    }
}
