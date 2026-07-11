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
    public function runtime_session_lifecycle_contains_no_exact_legacy_literals(): void
    {
        $violations = [];
        $analyzer = new LegacySessionLiteralAnalyzer(self::LEGACY);
        foreach ($this->runtimeFiles() as $file) {
            $source = (string) file_get_contents($file->getPathname());
            foreach ($analyzer->violations($source) as $legacy) {
                $violations[] = $file->getFilename().':'.$legacy;
            }
        }

        self::assertSame([], array_values(array_unique($violations)));
    }

    #[Test]
    public function analyzer_detects_session_alias_query_factory_variable_match_and_stage_bypasses(): void
    {
        $analyzer = new LegacySessionLiteralAnalyzer(self::LEGACY);
        $fixtures = [
            '<?php use App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationSession; EstimateGenerationSession::create(["status" => "created"]);',
            '<?php use App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationSession as Run; $attrs = ["status" => "queued"]; Run::factory()->state($attrs);',
            '<?php use App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationSession as Run; $query = Run::query(); $query->whereIn("status", ["processing", "failed"]);',
            '<?php use App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationSession; function x(EstimateGenerationSession $run) { return match ($run->status) { "blocked" => 1 }; }',
            '<?php use App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationSession; new EstimateGenerationSession(["status" => "generated"]);',
            '<?php $attributes = ["processing_stage" => "analyzed"];',
        ];

        foreach ($fixtures as $fixture) {
            self::assertNotSame([], $analyzer->violations($fixture), $fixture);
        }
    }

    #[Test]
    public function analyzer_ignores_exact_statuses_owned_by_documents_packages_quality_and_training(): void
    {
        $source = <<<'PHP'
<?php
EstimateGenerationDocument::query()->whereIn('status', ['queued', 'processing']);
$package->status = 'blocked';
$quality = ['level' => 'review_required'];
$training = ['status' => 'created'];
$current = 'estimate_review_required';
$documentStage = ['processing_stage' => 'preflight'];
PHP;

        self::assertSame([], (new LegacySessionLiteralAnalyzer(self::LEGACY))->violations($source));
    }

    #[Test]
    public function exact_legacy_matching_does_not_reject_current_or_other_domain_statuses(): void
    {
        self::assertNotContains('estimate_review_required', self::LEGACY);
        self::assertNotContains('processing_documents', self::LEGACY);
        self::assertNotContains('input_review_required', self::LEGACY);
    }

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

    /** @return iterable<SplFileInfo> */
    private function runtimeFiles(): iterable
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (! str_starts_with($relative, 'migrations/')) {
                yield $file;
            }
        }
    }
}

final class LegacySessionLiteralAnalyzer
{
    /** @param list<string> $legacy */
    public function __construct(private array $legacy) {}

    /** @return list<string> */
    public function violations(string $source): array
    {
        $exactLiterals = [];
        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $literal = stripcslashes(substr($token[1], 1, -1));
            if (in_array($literal, $this->legacy, true)) {
                $exactLiterals[$literal] = true;
            }
        }
        if ($exactLiterals === []) {
            return [];
        }

        $aliases = ['EstimateGenerationSession'];
        if (preg_match_all(
            '/use\s+App\\\\BusinessModules\\\\Addons\\\\EstimateGeneration\\\\Models\\\\EstimateGenerationSession(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/',
            $source,
            $imports,
        )) {
            foreach ($imports[1] as $alias) {
                $aliases[] = $alias !== '' ? $alias : 'EstimateGenerationSession';
            }
        }
        $aliasPattern = implode('|', array_map(static fn (string $alias): string => preg_quote($alias, '/'), array_unique($aliases)));
        $sessionVariables = [];
        if (preg_match_all('/(?:'.$aliasPattern.')\s+(\$[A-Za-z_][A-Za-z0-9_]*)/', $source, $parameters)) {
            $sessionVariables = $parameters[1];
        }
        $queryVariables = [];
        if (preg_match_all('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:'.$aliasPattern.')::(?:query|factory)\s*\(\s*\)/', $source, $queries)) {
            $queryVariables = $queries[1];
        }
        $receiverParts = ['(?:'.$aliasPattern.')::(?:query|factory)\s*\(\s*\)'];
        array_push(
            $receiverParts,
            ...array_map(static fn (string $variable): string => preg_quote($variable, '/'), $queryVariables),
        );
        $receiverPattern = '(?:'.implode('|', $receiverParts).')';

        $violations = [];
        foreach (array_keys($exactLiterals) as $legacy) {
            $value = preg_quote($legacy, '/');
            $quotedValue = '[\'\"]'.$value.'[\'\"]';
            $statusPair = '[\'\"]status[\'\"]\s*=>\s*'.$quotedValue;
            $patterns = [
                '/[\'\"]processing_stage[\'\"]\s*=>\s*'.$quotedValue.'/',
                '/new\s+(?:'.$aliasPattern.')\s*\([^;]*'.$statusPair.'/s',
                '/(?:'.$aliasPattern.')::(?:create|forceCreate)\s*\([^;]*'.$statusPair.'/s',
                '/(?:'.$aliasPattern.')::factory\s*\(\s*\)\s*->state\s*\([^;]*'.$statusPair.'/s',
                '/'.$receiverPattern.'\s*->where(?:In)?\s*\(\s*[\'\"]status[\'\"]\s*,[^;]*'.$quotedValue.'/s',
            ];
            foreach ($sessionVariables as $variable) {
                $patterns[] = '/'.preg_quote($variable, '/').'->status[^;]*'.$quotedValue.'/s';
            }

            if ($this->variableStatusPayloadFlowsIntoSession($source, $statusPair, $aliasPattern)
                || $this->matchesAny($source, $patterns)) {
                $violations[] = $legacy;
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    private function variableStatusPayloadFlowsIntoSession(string $source, string $statusPair, string $aliasPattern): bool
    {
        if (! preg_match_all('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*\[[^;]*'.$statusPair.'[^;]*\]\s*;/s', $source, $variables)) {
            return false;
        }
        foreach ($variables[1] as $variable) {
            if (preg_match('/(?:'.$aliasPattern.')::(?:create|forceCreate)\s*\(\s*'.preg_quote($variable, '/').'\s*\)/', $source) === 1
                || preg_match('/(?:'.$aliasPattern.')::factory\s*\(\s*\)\s*->state\s*\(\s*'.preg_quote($variable, '/').'\s*\)/', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $source, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }
}
