<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuthorizationSubjectReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ReportAuthorizationSubjectReaderOwnershipTest extends TestCase
{
    public function test_single_reader_owns_direct_run_and_export_subject_reads(): void
    {
        $reportingRoot = dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting';
        $readerPath = str_replace('\\', '/', $reportingRoot.'/Infrastructure/Persistence/EloquentReportAuthorizationSubjectReader.php');
        $implementations = [];
        foreach ($this->phpFiles($reportingRoot) as $file) {
            $normalizedFile = str_replace('\\', '/', $file);
            $source = file_get_contents($file);
            self::assertIsString($source);
            if (str_contains($source, 'implements ReportAuthorizationSubjectReader')) {
                $implementations[] = $normalizedFile;
            }
            if (str_contains($normalizedFile, '/Access/') && $normalizedFile !== $readerPath) {
                self::assertFalse(
                    str_contains($source, 'ReportRunRecord::query()') && str_contains($source, 'ReportExportRecord::query()'),
                    "Inline cross-aggregate authorization persistence found in {$file}.",
                );
            }
        }

        self::assertSame(
            [$readerPath],
            $implementations,
        );
        self::assertTrue(is_subclass_of(EloquentReportAuthorizationSubjectReader::class, ReportAuthorizationSubjectReader::class));
    }

    public function test_reader_has_closed_read_only_surface_and_no_store_or_container_dependency(): void
    {
        $reflection = new ReflectionClass(EloquentReportAuthorizationSubjectReader::class);
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === EloquentReportAuthorizationSubjectReader::class,
            ),
        );
        sort($methods);
        self::assertSame(['__construct', 'export', 'run'], $methods);

        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            self::assertStringNotContainsString('Store', (string) $parameter->getType());
            self::assertStringNotContainsString('Container', (string) $parameter->getType());
        }

        $source = file_get_contents($reflection->getFileName());
        self::assertIsString($source);
        foreach (['DB::transaction(', '->insert(', '->update(', '->delete(', 'app(', 'resolve('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
