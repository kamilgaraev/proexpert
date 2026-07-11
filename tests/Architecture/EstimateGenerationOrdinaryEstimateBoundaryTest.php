<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EstimateGenerationOrdinaryEstimateBoundaryTest extends TestCase
{
    private const LEGACY_ALLOWLIST = [
        'Models/EstimateGenerationLearningExample.php',
        'Models/EstimateGenerationSession.php',
        'Services/EstimateDraftPersistenceService.php',
        'Services/EstimateGenerationExcelExportService.php',
        'Services/Learning/EstimateGenerationLearningBootstrapService.php',
        'Services/Learning/EstimateGenerationLearningRecorder.php',
        'Services/Learning/EstimateLearningExampleExtractor.php',
    ];

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
            $relativePath = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            $source = (string) file_get_contents($file->getPathname());

            if ($path !== $allowed && preg_match('/App\\\\Models\\\\(Estimate|EstimateItem|EstimateSection)\\b/', $source) === 1) {
                $violations[] = $relativePath;
            }
        }

        sort($violations);

        self::assertSame(
            self::LEGACY_ALLOWLIST,
            $violations,
            'Обновите временный LEGACY_ALLOWLIST при удалении старых зависимостей; новые зависимости от обычных смет запрещены.'
        );
    }
}
