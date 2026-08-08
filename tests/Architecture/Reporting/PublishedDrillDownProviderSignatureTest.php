<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PublishedDrillDownProviderSignatureTest extends TestCase
{
    public function test_every_published_drill_down_provider_accepts_validated_input(): void
    {
        $root = dirname(__DIR__, 3).'/app';
        $legacyProviders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (! str_contains($source, 'ReportDrillDownProvider')) {
                continue;
            }

            if (str_contains($source, 'ReportDrillDownRequest $request')) {
                $legacyProviders[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        self::assertSame([], $legacyProviders);
    }
}
