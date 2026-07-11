<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EstimateGenerationOrdinaryEstimateBoundaryTest extends TestCase
{
    private const WRITE_ALLOWLIST = [
        'Application/Apply/LaravelGeneratedEstimateWriter.php',
    ];

    public function refreshDatabase(): void {}

    #[Test]
    public function only_apply_use_case_may_reference_ordinary_estimate_models(): void
    {
        $root = app_path('BusinessModules/Addons/EstimateGeneration');
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relativePath = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match(
                '/(Estimate|EstimateItem|EstimateItemResource|EstimateSection)::(?:create|update|delete|destroy|insert|upsert)\\b/',
                $source,
            ) === 1) {
                $violations[] = $relativePath;
            }
        }

        sort($violations);

        self::assertSame(
            self::WRITE_ALLOWLIST,
            $violations,
            'Только единый writer может зависеть от моделей обычных смет.'
        );
    }
}
