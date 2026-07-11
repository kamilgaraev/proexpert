<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class EstimateGenerationLegacySessionContractTest extends TestCase
{
    private const LEGACY = ['created', 'analyzed', 'generated', 'queued', 'processing', 'waiting_for_documents', 'ready_for_review', 'review_required', 'blocked'];

    #[Test]
    public function feature_session_fixtures_and_assertions_use_only_the_new_lifecycle(): void
    {
        $violations = [];
        foreach ($this->featureFiles() as $file) {
            $lines = file($file->getPathname()) ?: [];
            $insideSessionFixture = false;
            foreach ($lines as $index => $line) {
                if (str_contains($line, 'EstimateGenerationSession::') && str_contains($line, 'create([')) {
                    $insideSessionFixture = true;
                }
                if ($insideSessionFixture) {
                    foreach (self::LEGACY as $legacy) {
                        if (str_contains($line, "'status' => '{$legacy}'")) {
                            $violations[] = $file->getFilename().':'.($index + 1);
                        }
                    }
                    if (trim($line) === ']);') {
                        $insideSessionFixture = false;
                    }
                }
                if (str_contains($line, 'session->status')) {
                    foreach (self::LEGACY as $legacy) {
                        if (str_contains($line, "'{$legacy}'")) {
                            $violations[] = $file->getFilename().':'.($index + 1);
                        }
                    }
                }
                if (preg_match('/new GenerateEstimateDraftJob\([^,\)]*\)/', $line) === 1
                    && ! str_contains($line, 'GenerateEstimateDraftJob(123)')) {
                    $violations[] = $file->getFilename().':'.($index + 1);
                }
            }
        }

        self::assertSame([], array_values(array_unique($violations)));
    }

    /** @return iterable<SplFileInfo> */
    private function featureFiles(): iterable
    {
        $root = dirname(__DIR__, 2).'/tests/Feature/EstimateGeneration';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
