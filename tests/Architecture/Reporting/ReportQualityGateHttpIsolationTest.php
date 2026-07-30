<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportQualityGateHttpIsolationTest extends TestCase
{
    public function test_offline_quality_components_do_not_depend_on_http_layers(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            $root.'/app/BusinessModules/Core/Reporting/Application/Quality',
            $root.'/scripts/reporting/build-report-quality-evidence.php',
            $root.'/scripts/reporting/build-report-release-gate-bundle.php',
        ];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
                $files = iterator_to_array($iterator);
            } else {
                $files = [new \SplFileInfo($path)];
            }
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                self::assertDoesNotMatchRegularExpression('/AdminResponse|MobileResponse|LandingResponse|CustomerResponse|RenderReportErrors|Controller|FormRequest/', (string) file_get_contents($file->getPathname()));
            }
        }
    }
}
