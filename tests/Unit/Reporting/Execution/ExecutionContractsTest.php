<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\FakeReportExportDispatcher;
use Tests\Support\Reporting\FakeReportMaterializationDispatcher;
use Tests\Support\Reporting\FakeReportTransitionAudit;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ExecutionContractsTest extends TestCase
{
    public function test_execution_ports_keep_the_exact_native_signatures(): void
    {
        $this->assertInterfaceSurface(ReportExecutionClock::class, ['now']);
        $this->assertMethod(ReportExecutionClock::class, 'now', [], DateTimeImmutable::class);
        $this->assertInterfaceSurface(ReportMaterializationDispatcher::class, ['dispatch']);
        $this->assertMethod(ReportMaterializationDispatcher::class, 'dispatch', ['runId' => 'string'], 'void');
        $this->assertInterfaceSurface(ReportExportDispatcher::class, ['dispatch']);
        $this->assertMethod(ReportExportDispatcher::class, 'dispatch', ['exportId' => 'string'], 'void');
        $this->assertInterfaceSurface(ReportRunExecutionContextRehydrator::class, ['forRun']);
        $this->assertMethod(
            ReportRunExecutionContextRehydrator::class,
            'forRun',
            ['runId' => 'string'],
            \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class,
        );
        $this->assertInterfaceSurface(ReportExportExecutionContextRehydrator::class, ['forExport']);
        $this->assertMethod(
            ReportExportExecutionContextRehydrator::class,
            'forExport',
            ['exportId' => 'string'],
            \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class,
        );
        $this->assertInterfaceSurface(ReportTransitionAudit::class, ['append']);
        $this->assertMethod(
            ReportTransitionAudit::class,
            'append',
            [
                'eventId' => 'string',
                'eventType' => 'string',
                'context' => \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class,
                'subject' => 'array',
                'occurredAt' => DateTimeImmutable::class,
            ],
            'void',
        );
    }

    public function test_fake_clock_returns_the_exact_supplied_immutable_instant(): void
    {
        $instant = new DateTimeImmutable('2026-07-26T15:34:56.123456+03:00');
        $clock = new FakeReportExecutionClock($instant);

        $this->assertSame('UTC', $clock->now()->getTimezone()->getName());
        $this->assertSame('2026-07-26T12:34:56.123456+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
        $clock->advance(new DateInterval('PT90S'));
        $this->assertSame('2026-07-26T12:36:26.123456+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
        $this->assertFakeClassSurface(
            FakeReportExecutionClock::class,
            [
                '__construct' => [
                    'static' => false,
                    'return' => '',
                    'parameters' => [['instant', DateTimeImmutable::class, false, false, false, false, null]],
                ],
                'advance' => [
                    'static' => false,
                    'return' => 'void',
                    'parameters' => [['interval', DateInterval::class, false, false, false, false, null]],
                ],
                'now' => ['static' => false, 'return' => DateTimeImmutable::class, 'parameters' => []],
            ],
            [
                'instant' => ['private', DateTimeImmutable::class, false, false],
            ],
        );
    }

    public function test_system_clock_returns_current_utc_time(): void
    {
        $before = microtime(true);
        $actual = (new SystemReportExecutionClock())->now();
        $after = microtime(true);

        $this->assertSame('UTC', $actual->getTimezone()->getName());
        $this->assertGreaterThanOrEqual($before, (float) $actual->format('U.u'));
        $this->assertLessThanOrEqual($after, (float) $actual->format('U.u'));
    }

    public function test_dispatcher_fakes_retain_only_ordered_raw_ids(): void
    {
        $runs = new FakeReportMaterializationDispatcher();
        $exports = new FakeReportExportDispatcher();

        $runs->dispatch('run-2');
        $runs->dispatch('run-2');
        $runs->dispatch('run-1');
        $exports->dispatch('export-4');
        $exports->dispatch('export-4');
        $exports->dispatch('export-3');

        $this->assertSame(['run-2', 'run-2', 'run-1'], $runs->dispatchedIds());
        $this->assertSame(['export-4', 'export-4', 'export-3'], $exports->dispatchedIds());
        $this->assertFakeClassSurface(
            FakeReportMaterializationDispatcher::class,
            [
                'dispatch' => [
                    'static' => false,
                    'return' => 'void',
                    'parameters' => [['runId', 'string', false, false, false, false, null]],
                ],
                'dispatchedIds' => ['static' => false, 'return' => 'array', 'parameters' => []],
            ],
            [
                'dispatchedIds' => ['private', 'array', false, false],
            ],
        );
        $this->assertFakeClassSurface(
            FakeReportExportDispatcher::class,
            [
                'dispatch' => [
                    'static' => false,
                    'return' => 'void',
                    'parameters' => [['exportId', 'string', false, false, false, false, null]],
                ],
                'dispatchedIds' => ['static' => false, 'return' => 'array', 'parameters' => []],
            ],
            [
                'dispatchedIds' => ['private', 'array', false, false],
            ],
        );
    }

    public function test_audit_fake_records_ordered_envelopes_without_later_subject_mutation(): void
    {
        $context = (new ReportExecutionContextBuilder())->build();
        $audit = new FakeReportTransitionAudit();
        $subject = ['run_id' => 'run-1', 'identity' => ['snapshot_id' => 'snapshot-1']];
        $first = new DateTimeImmutable('2026-07-26T12:00:00+00:00');
        $second = new DateTimeImmutable('2026-07-26T12:01:00+00:00');

        $audit->append('evt-1', 'report.run.ready', $context, $subject, $first);
        $subject['identity']['snapshot_id'] = 'mutated';
        $audit->append('evt-2', 'report.export.ready', $context, ['export_id' => 'export-1'], $second);

        $this->assertSame(
            [
                [
                    'event_id' => 'evt-1',
                    'event_type' => 'report.run.ready',
                    'context' => $context,
                    'subject' => ['run_id' => 'run-1', 'identity' => ['snapshot_id' => 'snapshot-1']],
                    'occurred_at' => $first,
                ],
                [
                    'event_id' => 'evt-2',
                    'event_type' => 'report.export.ready',
                    'context' => $context,
                    'subject' => ['export_id' => 'export-1'],
                    'occurred_at' => $second,
                ],
            ],
            $audit->events(),
        );
        $this->assertFakeClassSurface(
            FakeReportTransitionAudit::class,
            [
                '__construct' => [
                    'static' => false,
                    'return' => '',
                    'parameters' => [['fails', 'bool', true, false, false, true, false]],
                ],
                'failing' => ['static' => true, 'return' => 'self', 'parameters' => []],
                'append' => [
                    'static' => false,
                    'return' => 'void',
                    'parameters' => [
                        ['eventId', 'string', false, false, false, false, null],
                        ['eventType', 'string', false, false, false, false, null],
                        [
                            'context',
                            \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class,
                            false,
                            false,
                            false,
                            false,
                            null,
                        ],
                        ['subject', 'array', false, false, false, false, null],
                        ['occurredAt', DateTimeImmutable::class, false, false, false, false, null],
                    ],
                ],
                'events' => ['static' => false, 'return' => 'array', 'parameters' => []],
            ],
            [
                'events' => ['private', 'array', false, false],
                'fails' => ['private', 'bool', false, true],
            ],
        );
    }

    public function test_audit_failure_is_observable_before_any_envelope_is_recorded(): void
    {
        $audit = FakeReportTransitionAudit::failing();

        try {
            $audit->append(
                'evt-run-ready-1',
                'report.run.ready',
                (new ReportExecutionContextBuilder())->build(),
                ['run_id' => 'run-1'],
                new DateTimeImmutable('2026-07-26T12:00:00+00:00'),
            );
            $this->fail('Expected audit dependency failure.');
        } catch (ReportContractException $exception) {
            $this->assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }

        $this->assertSame([], $audit->events());
    }

    #[DataProvider('blankAuditIdentity')]
    public function test_audit_fake_rejects_blank_event_identity_before_recording(string $eventId, string $eventType): void
    {
        $audit = new FakeReportTransitionAudit();

        try {
            $audit->append(
                $eventId,
                $eventType,
                (new ReportExecutionContextBuilder())->build(),
                ['run_id' => 'run-1'],
                new DateTimeImmutable('2026-07-26T12:00:00+00:00'),
            );
            $this->fail('Expected invalid audit identity.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('report_transition_audit_identity_invalid', $exception->getMessage());
        }

        $this->assertSame([], $audit->events());
    }

    public static function blankAuditIdentity(): array
    {
        return [
            'blank event id' => ['', 'report.run.ready'],
            'whitespace event id' => [" \t", 'report.run.ready'],
            'blank event type' => ['evt-1', ''],
            'whitespace event type' => ['evt-1', "\r\n"],
        ];
    }

    public function test_audit_fake_rejects_non_canonicalizable_subjects_before_recording(): void
    {
        $resource = fopen('php://memory', 'rb');
        $this->assertIsResource($resource);

        try {
            $invalidSubjects = [
                ['value' => $resource],
                ['value' => new \stdClass()],
                ['value' => static fn (): bool => true],
                ['value' => NAN],
                ['value' => INF],
                ['value' => -INF],
            ];

            foreach ($invalidSubjects as $index => $subject) {
                $audit = new FakeReportTransitionAudit();

                try {
                    $audit->append(
                        'evt-'.(string) $index,
                        'report.run.ready',
                        (new ReportExecutionContextBuilder())->build(),
                        $subject,
                        new DateTimeImmutable('2026-07-26T12:00:00+00:00'),
                    );
                    $this->fail('Expected non-canonicalizable audit subject.');
                } catch (InvalidArgumentException) {
                    $this->assertSame([], $audit->events());
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function test_task_two_owns_only_execution_mechanics_and_reuses_plan_one_a_context(): void
    {
        $paths = $this->taskTwoPaths();

        $this->assertCount(12, $paths);
        $this->assertSame([], $this->forbiddenDeclarations($paths));
        $runStoreDeclarations = $this->reportingProductionDeclarations('ReportRunStore');
        $canonicalRunStore = str_replace('\\', '/', dirname(__DIR__, 4)
            .'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php');
        $this->assertContains(count($runStoreDeclarations), [0, 1]);
        if ($runStoreDeclarations !== []) {
            $this->assertSame([$canonicalRunStore], $runStoreDeclarations);
        }
        $this->assertSame(
            \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext::class,
            (string) (new ReflectionClass(ReportRunExecutionContextRehydrator::class))
                ->getMethod('forRun')
                ->getReturnType(),
        );
    }

    #[DataProvider('forbiddenDeclarationFixtures')]
    public function test_ownership_scan_rejects_real_duplicate_declarations(string $declaration): void
    {
        $path = tempnam(sys_get_temp_dir(), 'report-task-2-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, "<?php\n".$declaration."\n");
            $this->assertNotSame([], $this->forbiddenDeclarations([$path]));
        } finally {
            @unlink($path);
        }
    }

    public static function forbiddenDeclarationFixtures(): array
    {
        return [
            'run store' => ['interface ReportRunStore {}'],
            'alternate registry' => ['interface AlternateReportRegistry {}'],
            'alternate map' => ['final class RuntimeReportBindingMap {}'],
            'alternate provider' => ['interface RuntimeReportProvider {}'],
            'context dto' => ['final class ReportExecutionContextDTO {}'],
            'shim' => ['final class ReportBindingShim {}'],
            'fallback' => ['final class FallbackReportDefinition {}'],
        ];
    }

    private function taskTwoPaths(): array
    {
        $root = dirname(__DIR__, 4);

        return [
            $root.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExecutionClock.php',
            $root.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportMaterializationDispatcher.php',
            $root.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportDispatcher.php',
            $root.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunExecutionContextRehydrator.php',
            $root.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportExecutionContextRehydrator.php',
            $root.'/app/BusinessModules/Core/Reporting/Application/Audit/ReportTransitionAudit.php',
            $root.'/app/BusinessModules/Core/Reporting/Infrastructure/Clock/SystemReportExecutionClock.php',
            $root.'/tests/Support/Reporting/FakeReportExecutionClock.php',
            $root.'/tests/Support/Reporting/FakeReportTransitionAudit.php',
            $root.'/tests/Support/Reporting/FakeReportMaterializationDispatcher.php',
            $root.'/tests/Support/Reporting/FakeReportExportDispatcher.php',
            __FILE__,
        ];
    }

    private function assertMethod(string $type, string $methodName, array $parameters, string $returnType): void
    {
        $method = (new ReflectionClass($type))->getMethod($methodName);
        $actualParameters = [];

        foreach ($method->getParameters() as $parameter) {
            $actualParameters[$parameter->getName()] = (string) $parameter->getType();
            $this->assertFalse($parameter->isOptional());
            $this->assertFalse($parameter->isVariadic());
            $this->assertFalse($parameter->isPassedByReference());
            $this->assertFalse($parameter->isDefaultValueAvailable());
        }

        $this->assertSame($parameters, $actualParameters);
        $this->assertSame($returnType, (string) $method->getReturnType());
        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    private function assertInterfaceSurface(string $type, array $methods): void
    {
        $reflection = new ReflectionClass($type);
        $actual = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($actual);
        sort($methods);

        $this->assertTrue($reflection->isInterface());
        $this->assertSame($methods, $actual);
    }

    private function assertFakeClassSurface(string $type, array $methods, array $properties): void
    {
        $reflection = new ReflectionClass($type);
        $actualMethods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $parameters = [];

            foreach ($method->getParameters() as $parameter) {
                $parameters[] = [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                    $parameter->isOptional(),
                    $parameter->isVariadic(),
                    $parameter->isPassedByReference(),
                    $parameter->isDefaultValueAvailable(),
                    $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                ];
            }

            $actualMethods[$method->getName()] = [
                'static' => $method->isStatic(),
                'return' => (string) $method->getReturnType(),
                'parameters' => $parameters,
            ];
        }

        $actualProperties = [];

        foreach ($reflection->getProperties() as $property) {
            $visibility = $property->isPrivate() ? 'private' : ($property->isProtected() ? 'protected' : 'public');
            $actualProperties[$property->getName()] = [
                $visibility,
                (string) $property->getType(),
                $property->isStatic(),
                $property->isReadOnly(),
            ];
        }

        ksort($actualMethods);
        ksort($methods);
        ksort($actualProperties);
        ksort($properties);

        $this->assertSame($methods, $actualMethods);
        $this->assertSame($properties, $actualProperties);
    }

    private function forbiddenDeclarations(array $paths): array
    {
        $forbidden = [];

        foreach ($paths as $path) {
            $tokens = token_get_all((string) file_get_contents($path));
            $expectName = false;

            foreach ($tokens as $token) {
                if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true)) {
                    $expectName = true;

                    continue;
                }

                if (!$expectName || !is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                $expectName = false;
                $name = $token[1];

                if (
                    $name === 'ReportRunStore'
                    || preg_match('/(?:Registry|BindingMap|Provider|DTO|Shim|Fallback)/', $name) === 1
                ) {
                    $forbidden[] = $path.':'.$name;
                }
            }
        }

        return $forbidden;
    }

    private function reportingProductionDeclarations(string $expectedName): array
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $declarations = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $tokens = token_get_all((string) file_get_contents($path));
            $expectName = false;

            foreach ($tokens as $token) {
                if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true)) {
                    $expectName = true;

                    continue;
                }
                if (!$expectName || !is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                $expectName = false;
                if ($token[1] === $expectedName) {
                    $declarations[] = str_replace('\\', '/', $path);
                }
            }
        }

        sort($declarations, SORT_STRING);

        return $declarations;
    }
}
