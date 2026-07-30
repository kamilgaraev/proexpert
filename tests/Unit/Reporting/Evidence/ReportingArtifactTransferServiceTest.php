<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\ReportingArtifactTransferService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReportingArtifactTransferServiceTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function test_activation_transfer_copies_only_the_closed_artifact_and_schema_paths(): void
    {
        $source = $this->directory();
        $destination = $this->directory();
        $this->write($source, 'build/reports/report-catalog-activation.json', "{\"status\":\"catalog_activated\"}\n");
        $this->write($source, 'docs/reports/contracts/report-catalog-activation.schema.json', "{}\n");
        $this->write($source, 'docs/reports/contracts/reporting-artifact-transfer.schema.json', "{}\n");

        $transfer = (new ReportingArtifactTransferService())->transfer(
            'activation',
            $source,
            'build/reports/report-catalog-activation.json',
            'docs/reports/contracts/report-catalog-activation.schema.json',
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('a', 40),
            null,
            $destination,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            false,
        );

        self::assertSame('report_catalog_activation_transfer', $transfer->artifactId);
        self::assertSame('artifact_transferred', $transfer->status);
        self::assertSame(
            file_get_contents($source.'/build/reports/report-catalog-activation.json'),
            file_get_contents($destination.'/build/reports/intake/report-catalog-activation.json'),
        );
        self::assertSame(
            file_get_contents($source.'/docs/reports/contracts/reporting-artifact-transfer.schema.json'),
            file_get_contents($destination.'/build/reports/intake/contracts/reporting-artifact-transfer.schema.json'),
        );
    }

    public function test_check_mode_never_creates_destination_files(): void
    {
        $source = $this->directory();
        $destination = $this->directory();
        $this->write($source, 'build/reports/report-catalog-activation.json', "{\"status\":\"catalog_activated\"}\n");
        $this->write($source, 'docs/reports/contracts/report-catalog-activation.schema.json', "{}\n");
        $this->write($source, 'docs/reports/contracts/reporting-artifact-transfer.schema.json', "{}\n");

        (new ReportingArtifactTransferService())->transfer(
            'activation', $source, 'build/reports/report-catalog-activation.json',
            'docs/reports/contracts/report-catalog-activation.schema.json', str_repeat('a', 40),
            str_repeat('b', 40), str_repeat('a', 40), null, $destination,
            new DateTimeImmutable('2026-07-26T00:00:00Z'), true,
        );

        self::assertFileDoesNotExist($destination.'/build/reports/intake/report-catalog-activation.json');
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().'/most-transfer-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $this->directories[] = $directory;

        return $directory;
    }

    private function write(string $root, string $path, string $contents): void
    {
        mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    private function removeDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
