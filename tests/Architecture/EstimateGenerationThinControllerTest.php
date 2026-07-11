<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class EstimateGenerationThinControllerTest extends TestCase
{
    #[Test]
    public function mutation_controllers_do_not_own_workflow_readiness_or_queue_logic(): void
    {
        foreach ([
            'EstimateGenerationSessionController.php',
            'EstimateGenerationActionController.php',
            'EstimateGenerationDocumentController.php',
            'EstimateGenerationPackageController.php',
            'EstimateGenerationReviewController.php',
        ] as $file) {
            $source = file_get_contents($this->root().'/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/'.$file);
            self::assertIsString($source);
            self::assertStringNotContainsString('::dispatch(', $source);
            self::assertStringNotContainsString('->forceFill(', $source);
            self::assertStringNotContainsString('DB::transaction', $source);
            self::assertStringNotContainsString('->lockForUpdate(', $source);
            self::assertStringNotContainsString('MutationPolicy', $source);
        }
    }

    #[Test]
    public function feature_consumers_do_not_pass_plain_requests_to_typed_generation_endpoints(): void
    {
        $root = $this->root().'/tests/Feature/EstimateGeneration';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString("= Request::create('/generate'", $source, $file->getFilename());
            self::assertStringNotContainsString("= Request::create('/analyze'", $source, $file->getFilename());
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
