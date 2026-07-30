<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\Process\Process;

final class PlanOneBCrossFileSymbolTest extends TestCase
{
    public function test_evidence_interfaces_have_the_exact_locked_signatures(): void
    {
        $validate = new ReflectionMethod(PlanOneBEvidenceValidator::class, 'validate');
        $build = new ReflectionMethod(PlanOneBEvidenceBuilder::class, 'build');

        $this->assertMethod($validate, ['document:array'], 'void');
        $this->assertMethod(
            $build,
            [
                'planOneA:'.PlanOneACompletionRef::class,
                'checks:array',
                'generatedAt:'.DateTimeImmutable::class,
            ],
            'array',
        );
    }

    public function test_schema_closes_every_object_and_locks_all_ordered_gates(): void
    {
        $schema = $this->decode($this->root().'/docs/reports/contracts/plan-1b-evidence.schema.json');

        $this->assertClosedObjects($schema, '$');
        self::assertCount(28, $schema['properties']['gates']['prefixItems']);
        self::assertFalse($schema['properties']['gates']['items']);
    }

    public function test_fixture_symbols_match_real_evidence_declarations(): void
    {
        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');
        $declared = [];

        foreach (glob($this->root().'/app/BusinessModules/Core/Reporting/Application/Evidence/*.php') ?: [] as $path) {
            $tokens = token_get_all((string) file_get_contents($path));
            foreach ($tokens as $index => $token) {
                if (! is_array($token) || $token[0] !== T_CLASS) {
                    continue;
                }
                for ($offset = $index + 1, $count = count($tokens); $offset < $count; $offset++) {
                    if (is_array($tokens[$offset]) && $tokens[$offset][0] === T_STRING) {
                        $declared[] = $tokens[$offset][1];
                        break;
                    }
                }
            }
        }
        sort($declared, SORT_STRING);

        self::assertSame($declared, $fixture['ownership']['plan_1b_symbols']);
    }

    public function test_every_locked_gate_command_targets_an_existing_phpunit_test_path(): void
    {
        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');

        foreach ($fixture['gates'] as $gate) {
            if ($gate['id'] === 'static_analysis') {
                self::assertSame(
                    'php -l app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php'
                    .' && php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist'
                    .' app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php'
                    .' app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php',
                    $gate['command'],
                );

                continue;
            }
            self::assertMatchesRegularExpression(
                '/^php vendor\/bin\/phpunit (tests\/[A-Za-z0-9_\/]+Test\.php) --no-coverage$/D',
                $gate['command'],
            );
            preg_match(
                '/^php vendor\/bin\/phpunit (tests\/[A-Za-z0-9_\/]+Test\.php) --no-coverage$/D',
                $gate['command'],
                $matches,
            );
            self::assertFileExists($this->root().'/'.$matches[1]);
        }
    }

    public function test_canonical_plan_is_tracked_and_all_create_paths_exclude_plan_one_a_symbols(): void
    {
        $relativePath = 'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md';
        $path = $this->root().'/'.$relativePath;
        self::assertFileExists($path);

        $tracked = new Process(['git', 'ls-files', '--error-unmatch', $relativePath], $this->root());
        $tracked->run();
        self::assertSame(0, $tracked->getExitCode(), $tracked->getErrorOutput());

        $plan = (string) file_get_contents($path);
        preg_match_all('/^- Create:\s*(.+)$/m', $plan, $entries);
        self::assertCount(255, $entries[1]);

        $createdPaths = [];
        foreach ($entries[1] as $entry) {
            preg_match_all('/`([^`]+)`/', $entry, $paths);
            self::assertNotEmpty($paths[1], $entry);
            array_push($createdPaths, ...$paths[1]);
        }

        self::assertCount(259, $createdPaths);
        self::assertCount(259, array_unique($createdPaths));

        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');
        $createdSymbols = array_map(
            static fn (string $createdPath): string => pathinfo(str_replace('\\', '/', $createdPath), PATHINFO_FILENAME),
            $createdPaths,
        );

        self::assertSame(
            [],
            array_values(array_intersect($fixture['ownership']['plan_1a_symbols'], $createdSymbols)),
        );
    }

    private function assertMethod(ReflectionMethod $method, array $parameters, string $returnType): void
    {
        self::assertTrue($method->isPublic());
        self::assertSame($returnType, $this->typeName($method->getReturnType()));
        self::assertSame(
            $parameters,
            array_map(
                fn (ReflectionParameter $parameter): string => $parameter->getName().':'.$this->typeName($parameter->getType()),
                $method->getParameters(),
            ),
        );
        foreach ($method->getParameters() as $parameter) {
            self::assertFalse($parameter->allowsNull());
            self::assertFalse($parameter->isOptional());
        }
    }

    private function assertClosedObjects(array $node, string $path): void
    {
        if (($node['type'] ?? null) === 'object') {
            self::assertArrayHasKey('additionalProperties', $node, $path);
            self::assertFalse($node['additionalProperties'], $path);
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->assertClosedObjects($value, $path.'/'.$key);
            }
        }
    }

    private function typeName(?\ReflectionType $type): string
    {
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }

    private function decode(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
