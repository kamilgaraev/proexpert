<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBGateArtifactRecorder;
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
        $recordPhpUnit = new ReflectionMethod(PlanOneBGateArtifactRecorder::class, 'recordPhpUnit');
        $recordStaticAnalysis = new ReflectionMethod(PlanOneBGateArtifactRecorder::class, 'recordStaticAnalysis');

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
        $this->assertMethod(
            $recordPhpUnit,
            [
                'gateId:string',
                'processResult:array',
                'resultArtifactPath:string',
                'measurementArtifactPath:string',
                'repositoryRevision:string',
            ],
            'array',
        );
        $this->assertMethod(
            $recordStaticAnalysis,
            [
                'resultArtifactPath:string',
                'repositoryRevision:string',
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
            $definition = PlanOneBGateArtifactRecorder::definition($gate['id']);
            self::assertSame($definition['command'], $gate['command']);
            self::assertFileExists($this->root().'/scripts/reporting/run-plan-1b-gate.php');
            foreach ($definition['producer']['test_paths'] as $path) {
                self::assertFileExists($this->root().'/'.$path);
                self::assertStringContainsString($path, $gate['command']);
            }
            if ($gate['id'] === 'static_analysis') {
                self::assertStringContainsString('--error-format=json', $gate['command']);
                self::assertStringContainsString('--no-progress', $gate['command']);
            } else {
                self::assertStringEndsWith(
                    '--no-coverage --log-junit '.$definition['producer']['result_artifact_path'],
                    $gate['command'],
                );
            }
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
        self::assertStringContainsString(
            'Plans 2 and 3 implement only Plan 1a provider ports and supply candidate bindings to Plan 1c',
            $plan,
        );
        self::assertStringContainsString(
            'Plan 1c supplies the published registry/map and owns every publication transition',
            $plan,
        );
        self::assertStringContainsString(
            'Plan 4 owns evidence verification and deployment rollout only',
            $plan,
        );
        preg_match_all('/^- Create:\s*(.+)$/m', $plan, $entries);
        self::assertCount(258, $entries[1]);

        $createdPaths = [];
        foreach ($entries[1] as $entry) {
            preg_match_all('/`([^`]+)`/', $entry, $paths);
            self::assertNotEmpty($paths[1], $entry);
            array_push($createdPaths, ...$paths[1]);
        }

        self::assertCount(262, $createdPaths);
        self::assertCount(262, array_unique($createdPaths));

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
            self::assertSame(
                $parameter->getName() === 'measurementArtifactPath',
                $parameter->allowsNull(),
            );
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
