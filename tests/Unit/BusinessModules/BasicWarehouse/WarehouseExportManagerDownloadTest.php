<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseExportManager;
use App\Services\Storage\FileService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WarehouseExportManagerDownloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('downloadCases')]
    public function test_it_signs_downloads_with_original_filename_and_preserved_lifetime(string $filename, int $minutes): void
    {
        $path = 'org-38/exports/warehouse/fixture/658593d1-fdeb-4bae-bac5-c34056b8ebfb/'.$filename;
        $expectedUrl = 'https://download.example.test/prepared-document';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryUrl')
            ->with($path, $minutes)
            ->andReturn('https://download.example.test/inline-document');
        $files->shouldReceive('temporaryDownloadUrl')
            ->with($path, $minutes * 60, $filename)
            ->andReturn($expectedUrl);

        $manager = new class($files) extends WarehouseExportManager
        {
            protected function registerDefaultStrategies(): void {}
        };

        self::assertSame($expectedUrl, $manager->getTemporaryUrl($path, $minutes));
        $files->shouldHaveReceived('temporaryDownloadUrl')->once();
    }

    public static function downloadCases(): array
    {
        return [
            'inventory-default' => ['INV3_inv-20261020-0006.xlsx', 15],
            'receipt' => ['M4_УЧ-НДС-102-20261024.xlsx', 15],
            'acceptance' => ['M7_receipt-15.xlsx', 15],
            'limit-card' => ['M8_УЧ-102.xlsx', 15],
            'requirement' => ['M11_requirement-2.xlsx', 15],
            'transfer' => ['M15_transfer-3.xlsx', 15],
            'stock-card' => ['M17_material-5.xlsx', 15],
            'write-off' => ['write_off_act-4.xlsx', 15],
            'custom-lifetime' => ['INV3_custom.xlsx', 7],
        ];
    }
}
