<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkFixtureDescriptorValidator;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifestException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UniversalBenchmarkFormatsTest extends TestCase
{
    public function test_actual_xlsx_png_and_utf8_description_are_supported_fixture_formats(): void
    {
        $validator = new BenchmarkFixtureDescriptorValidator;
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['parameter', 'value', 'unit'], ['length', 5, 'm']]);
        $path = tempnam(sys_get_temp_dir(), 'most-benchmark-source-');
        try {
            (new Xlsx($book))->save($path);
            $validator->validateBytes(file_get_contents($path), BenchmarkSourceType::Spreadsheet, 'input.xlsx', []);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $validator->validateBytes($png, BenchmarkSourceType::SitePhoto, 'input.png', []);
        $validator->validateBytes('Ремонт: размеры требуется уточнить.', BenchmarkSourceType::TextDescription, 'input.txt', []);
        self::assertCount(10, BenchmarkSourceType::cases());
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_or_mislabeled_sources_fail_before_any_adapter(string $body, BenchmarkSourceType $type, string $name): void
    {
        $this->expectException(BenchmarkManifestException::class);
        (new BenchmarkFixtureDescriptorValidator)->validateBytes($body, $type, $name, []);
    }

    public static function invalidSources(): array
    {
        return [['not a zip', BenchmarkSourceType::Spreadsheet, 'input.xlsx'],
            ['not an image', BenchmarkSourceType::SitePhoto, 'input.png'],
            ["bad\0text", BenchmarkSourceType::TextDescription, 'input.txt'],
            ["\xff", BenchmarkSourceType::TextDescription, 'input.txt'],
            [str_repeat('a', 65537), BenchmarkSourceType::TextDescription, 'input.txt'],
            ['Описание', BenchmarkSourceType::TextDescription, 'input.xlsx']];
    }

    public function test_zip_with_workbook_names_but_unsafe_paths_is_rejected_without_extraction(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-unsafe-xlsx-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach (['[Content_Types].xml', 'xl/workbook.xml', '_rels/.rels', '../escaped.xml'] as $name) {
            $zip->addFromString($name, '<root/>');
        }
        $zip->close();
        try {
            $this->expectException(BenchmarkManifestException::class);
            (new BenchmarkFixtureDescriptorValidator)->validateBytes(file_get_contents($path), BenchmarkSourceType::Spreadsheet, 'input.xlsx', []);
        } finally {
            unlink($path);
        }
    }
}
