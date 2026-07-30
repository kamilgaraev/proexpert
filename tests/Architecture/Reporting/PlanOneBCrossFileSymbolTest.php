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

require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php';
require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php';

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

    public function test_schema_closes_every_object_and_fixture_symbols_match_real_evidence_declarations(): void
    {
        $schema = $this->decode($this->root().'/docs/reports/contracts/plan-1b-evidence.schema.json');
        $this->assertClosedObjects($schema, '$');
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
        self::assertSame(
            [],
            array_values(array_intersect(
                $fixture['ownership']['plan_1a_symbols'],
                $fixture['ownership']['plan_1b_symbols'],
            )),
        );
    }

    public function test_canonical_plan_is_tracked_and_has_no_plan_one_a_create_collision_in_task_fourteen(): void
    {
        $path = $this->root().'/docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md';
        self::assertFileExists($path);
        $tracked = new Process(
            ['git', 'ls-files', '--error-unmatch', 'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md'],
            $this->root(),
        );
        $tracked->run();
        self::assertSame(0, $tracked->getExitCode(), $tracked->getErrorOutput());
        $plan = (string) file_get_contents($path);
        $task = strstr($plan, '### Task 14:', false);
        self::assertIsString($task);
        $task = strstr($task, "\n---", true);
        self::assertIsString($task);
        preg_match_all('/^- Create: `[^`]*\\/([A-Za-z_][A-Za-z0-9_]*)\\.(?:php|json)`$/m', $task, $matches);

        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');
        self::assertSame(
            [],
            array_values(array_intersect($fixture['ownership']['plan_1a_symbols'], $matches[1])),
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
