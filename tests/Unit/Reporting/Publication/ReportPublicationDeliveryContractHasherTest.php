<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationDeliveryContractHasher;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportPublicationDeliveryContractHasherTest extends TestCase
{
    public function test_pdf_bundle_pins_concrete_adapter_template_configuration_and_dependency_lock(): void
    {
        $descriptor = $this->descriptor(PdfReportExportRenderer::class, 'pdf');
        $paths = array_column($descriptor['project_files'], 'path');
        $packages = array_column($descriptor['composer_packages'], 'name');

        self::assertContains(
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/DompdfReportPdfDocumentRenderer.php',
            $paths,
        );
        self::assertContains('resources/views/reports/exports/canonical-report-pdf.blade.php', $paths);
        self::assertContains('config/dompdf.php', $paths);
        self::assertContains('dompdf/dompdf', $packages);
        self::assertContains('barryvdh/laravel-dompdf', $packages);
        self::assertSame(['dom', 'mbstring'], array_column($descriptor['runtime_extensions'], 'name'));
    }

    public function test_xlsx_bundle_pins_shared_cell_normalizer_and_zip_runtime(): void
    {
        $descriptor = $this->descriptor(XlsxReportExportRenderer::class, 'xlsx');

        self::assertContains(
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocumentBuilder.php',
            array_column($descriptor['project_files'], 'path'),
        );
        self::assertSame(['mbstring', 'zip'], array_column($descriptor['runtime_extensions'], 'name'));
    }

    public function test_renderer_hash_must_match_the_current_implementation_bytes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_publication_renderer_contract_invalid');

        (new ReportPublicationDeliveryContractHasher)->descriptor(
            'xlsx',
            XlsxReportExportRenderer::class,
            new Sha256Hash(str_repeat('a', 64)),
            '1.0.0',
            new Sha256Hash(str_repeat('b', 64)),
            new Sha256Hash(str_repeat('c', 64)),
            ['export_xlsx_contract'],
        );
    }

    private function descriptor(string $rendererClass, string $format): array
    {
        $file = (new ReflectionClass($rendererClass))->getFileName();
        self::assertIsString($file);

        return (new ReportPublicationDeliveryContractHasher)->descriptor(
            $format,
            $rendererClass,
            new Sha256Hash((string) hash_file('sha256', $file)),
            '1.0.0',
            new Sha256Hash(str_repeat('b', 64)),
            new Sha256Hash(str_repeat('c', 64)),
            ['export_'.$format.'_contract'],
        );
    }
}
