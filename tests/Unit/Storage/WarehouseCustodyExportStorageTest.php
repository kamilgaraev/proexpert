<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyExportService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use InvalidArgumentException;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WarehouseCustodyExportStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_stores_export_as_private_immutable_user_scoped_object(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->withArgs(static function (string $path, string $content, string $mime, string $sha256): bool {
                return preg_match(
                    '#^org-12/warehouse/exports/user-34/custody/summary/[0-9a-f-]{36}\.xlsx$#D',
                    $path,
                ) === 1
                    && $content !== ''
                    && $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                    && hash('sha256', $content) === $sha256;
            })
            ->andReturnUsing(static fn (string $path, string $content, string $mime, string $sha256): CurrentStoredFile => new CurrentStoredFile(
                $path,
                'etag-warehouse',
                strlen($content),
                $sha256,
                $mime,
            ));
        $service = new WarehouseCustodyExportService($this->custodyService(), $files);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Warehouse export');
        $save = new ReflectionMethod($service, 'saveSpreadsheet');

        $stored = $save->invoke($service, $spreadsheet, 12, 34, 'summary');

        self::assertInstanceOf(CurrentStoredFile::class, $stored);
        self::assertMatchesRegularExpression(
            '#^org-12/warehouse/exports/user-34/custody/summary/[0-9a-f-]{36}\.xlsx$#D',
            $stored->key,
        );
        self::assertSame('etag-warehouse', $stored->etag);
    }

    public function test_issues_download_url_only_for_matching_organization_and_user_path(): void
    {
        $path = 'org-12/warehouse/exports/user-34/custody/summary/object.xlsx';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryDownloadUrl')
            ->once()
            ->with($path, 900)
            ->andReturn('https://s3.twcstorage.ru/signed');
        $service = new WarehouseCustodyExportService($this->custodyService(), $files);

        self::assertSame(
            'https://s3.twcstorage.ru/signed',
            $service->temporaryUrl(12, 34, $path, 15),
        );
    }

    public function test_rejects_download_url_for_another_user_path(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryDownloadUrl');
        $service = new WarehouseCustodyExportService($this->custodyService(), $files);

        $this->expectException(InvalidArgumentException::class);

        $service->temporaryUrl(
            12,
            34,
            'org-12/warehouse/exports/user-35/custody/summary/object.xlsx',
            15,
        );
    }

    private function custodyService(): WarehouseCustodyService
    {
        return (new ReflectionClass(WarehouseCustodyService::class))->newInstanceWithoutConstructor();
    }
}
