<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EstimateGenerationOrdinaryEstimateBoundaryTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    #[Test]
    public function only_apply_use_case_may_reference_ordinary_estimate_models(): void
    {
        $root = app_path('BusinessModules/Addons/EstimateGeneration');
        $allowed = str_replace('\\', '/', $root . '/Application/Apply/ApplyGeneratedEstimate.php');
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $source = (string) file_get_contents($file->getPathname());

            if ($path !== $allowed && preg_match('/App\\\\Models\\\\(Estimate|EstimateItem|EstimateSection)\\b/', $source) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
    }
}
