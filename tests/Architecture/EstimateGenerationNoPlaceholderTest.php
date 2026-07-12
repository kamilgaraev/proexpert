<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationNoPlaceholderTest extends TestCase
{
    #[Test]
    public function runtime_contains_no_placeholder_or_rule_based_provider(): void
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        $source = '';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        self::assertStringNotContainsString('cad_placeholder_v1', $source);
        self::assertStringNotContainsString('RuleBasedDrawingAnalysisProvider', $source);
        self::assertStringNotContainsString('RuleBasedNormativeCandidateReranker', $source);
    }
}
