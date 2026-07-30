<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\ReportingArtifactTransferService;
use DateTimeImmutable;
use Opis\JsonSchema\CompliantValidator;
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

    /**
     * @dataProvider transferModes
     */
    public function test_transfer_descriptor_validates_against_the_transfer_schema_for_each_mode(
        string $kind,
        string $sourcePath,
        string $schemaPath,
        string $status,
        string $descriptorPath,
    ): void {
        $source = $this->directory();
        $destination = $this->directory();
        $this->write($source, $sourcePath, json_encode(['status' => $status], JSON_THROW_ON_ERROR)."\n");
        $this->write($source, $schemaPath, "{}\n");
        $this->writeTransferSchema($source);

        $adminTransfer = $kind === 'release' ? $this->adminTransfer() : null;
        (new ReportingArtifactTransferService())->transfer(
            $kind,
            $source,
            $sourcePath,
            $schemaPath,
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            $adminTransfer,
            $destination,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            false,
        );

        $descriptor = json_decode((string) file_get_contents($destination.'/'.$descriptorPath), false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode((string) file_get_contents($source.'/docs/reports/contracts/reporting-artifact-transfer.schema.json'), false, 512, JSON_THROW_ON_ERROR);

        self::assertSame(hash_file('sha256', $source.'/'.$sourcePath), $descriptor->source_sha256, $kind);
        self::assertSame(hash_file('sha256', $destination.'/'.$descriptor->destination_path), $descriptor->destination_sha256, $kind);
        self::assertTrue((new CompliantValidator())->validate($descriptor, $schema)->isValid(), $kind);
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function transferModes(): array
    {
        return [
            'activation' => ['activation', 'build/reports/report-catalog-activation.json', 'docs/reports/contracts/report-catalog-activation.schema.json', 'catalog_activated', 'build/reports/intake/report-catalog-activation.transfer.json'],
            'admin evidence' => ['admin-evidence', 'docs/reports/admin-evidence.json', 'docs/reports/contracts/report-admin-evidence.schema.json', 'admin_evidence_passed', 'build/reports/intake/plan-4-admin-evidence.transfer.json'],
            'release' => ['release', 'build/reports/report-release-evidence.json', 'docs/reports/contracts/report-quality-evidence.schema.json', 'release_passed', 'build/reports/intake/report-release-evidence.transfer.json'],
        ];
    }

    private function adminTransfer(): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportingArtifactTransfer
    {
        $source = $this->directory();
        $destination = $this->directory();
        $this->write($source, 'docs/reports/admin-evidence.json', "{\"status\":\"admin_evidence_passed\"}\n");
        $this->write($source, 'docs/reports/contracts/report-admin-evidence.schema.json', "{}\n");
        $this->writeTransferSchema($source);

        return (new ReportingArtifactTransferService())->transfer(
            'admin-evidence',
            $source,
            'docs/reports/admin-evidence.json',
            'docs/reports/contracts/report-admin-evidence.schema.json',
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            null,
            $destination,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            false,
        );
    }

    private function writeTransferSchema(string $root): void
    {
        $schema = file_get_contents(dirname(__DIR__, 4).'/docs/reports/contracts/reporting-artifact-transfer.schema.json');
        self::assertIsString($schema);
        $this->write($root, 'docs/reports/contracts/reporting-artifact-transfer.schema.json', $schema);
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
