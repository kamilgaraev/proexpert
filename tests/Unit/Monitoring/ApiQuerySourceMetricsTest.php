<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\ApiQueryMetrics;
use App\Services\Logging\SensitiveDataRedactor;
use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\User;
use App\Services\Logging\SecurityLogger;
use App\Services\Logging\SafeLogWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class ApiQuerySourceMetricsTest extends TestCase
{
    private Container $previousContainer;

    public function test_access_read_diagnostics_are_closed_private_request_local_and_opt_in(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $frame = new class {
            public string $privateValue = 'private-frame-value';
            public function __toString(): string { throw new \LogicException('frame_must_not_be_read'); }
        };
        $store = new Repository(new ArrayStore);
        ApiQueryMetrics::recordAccessRead('module_load_full', $frame, $store, 923456789);
        ApiQueryMetrics::recordAccessRead('module_load_full', $frame, $store, 923456789);
        ApiQueryMetrics::recordAccessRead('private-event', $frame, $store, 923456789);
        $reads = $metrics->summary()['access_reads'];
        self::assertSame(['module_load_full'], array_keys($reads));
        self::assertSame(['count' => 2, 'frames' => 1, 'stores' => 1, 'organizations' => 1,
            'objects_dropped' => 0, 'organizations_dropped' => 0, 'callers' => ['other' => 2]], $reads['module_load_full']);
        self::assertSame($reads, (new SensitiveDataRedactor)->redact(['access_reads' => $reads])['access_reads']);
        $encoded = json_encode($reads, JSON_THROW_ON_ERROR);
        foreach (['private', '923456789', get_class($frame), 'Repository', 'module_load_full:frames'] as $value) {
            self::assertStringNotContainsString($value, $encoded);
        }
        [$request, $disabled] = $this->metrics(false);
        app()->instance('request', $request);
        ApiQueryMetrics::recordAccessRead('module_load_full', $frame, $store, 923456789);
        self::assertArrayNotHasKey('access_reads', $disabled->summary());
        [$request, $fresh] = $this->metrics();
        app()->instance('request', $request);
        self::assertSame([], $fresh->summary()['access_reads']);
        $request->attributes = new class extends \Symfony\Component\HttpFoundation\ParameterBag {
            public function get(string $key, mixed $default = null): mixed { throw new \LogicException('private-metrics-failure'); }
        };
        ApiQueryMetrics::recordAccessRead('module_load_full', $frame, $store, 923456789);
        self::assertSame([], $fresh->summary()['access_reads']);
    }

    public function test_access_read_cardinality_is_bounded_without_retaining_dead_scopes(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $frames = [];
        for ($index = 1; $index <= 140; $index++) {
            $frames[] = $frame = new \stdClass;
            ApiQueryMetrics::recordAccessRead('policy_enter', $frame, null, $index);
        }
        $reads = $metrics->summary()['access_reads']['policy_enter'];
        self::assertSame(140, $reads['count']);
        self::assertSame(128, $reads['frames']);
        self::assertSame(64, $reads['organizations']);
        self::assertSame(12, $reads['objects_dropped']);
        self::assertSame(76, $reads['organizations_dropped']);
        $reference = \WeakReference::create($frames[0]);
        unset($frames, $frame);
        self::assertNull($reference->get());
        ApiQueryMetrics::recordAccessRead('policy_enter', new \stdClass, null, 1);
        self::assertSame(129, $metrics->summary()['access_reads']['policy_enter']['frames']);
    }

    public function test_role_catalog_redis_reads_keep_only_numeric_opt_in_request_local_aggregates(): void
    {
        [$request, $metrics] = $this->metrics();
        $connection = new class {
            public function getName(): string { return 'private-connection'; }
        };
        $event = new \Illuminate\Redis\Events\CommandExecuted('get', ['private-prefix:authorization_roles:v3'], 2.5, $connection);
        ApiQueryMetrics::recordRoleCatalogRedisGet($request, $event);
        $second = clone $event;
        $second->command = 'GET';
        $second->time = 3.0;
        ApiQueryMetrics::recordRoleCatalogRedisGet($request, $second);
        $badValues = [
            ['command', 'mget'], ['command', new \stdClass],
            ['parameters', ['private-other-key']], ['parameters', ['private-prefix:authorization_roles:v2']], ['parameters', ['authorization_roles:v3', 'private-value']],
            ['parameters', [new \stdClass]], ['parameters', null],
            ['parameters', [str_repeat('x', 4097).'authorization_roles:v3']],
            ['time', INF], ['time', NAN], ['time', -1], ['time', '2.0'], ['time', new \stdClass],
        ];
        foreach ($badValues as [$property, $value]) {
            $invalid = clone $event;
            $invalid->{$property} = $value;
            ApiQueryMetrics::recordRoleCatalogRedisGet($request, $invalid);
        }
        self::assertSame(['observed' => true, 'count' => 2, 'total_ms' => 5.5, 'max_ms' => 3.0], $metrics->summary()['role_catalog_redis_get']);
        foreach (['private-prefix', 'authorization_roles', 'private-connection', 'private-value', 'parameters', 'connectionName'] as $private) {
            self::assertStringNotContainsString($private, json_encode($metrics->summary(), JSON_THROW_ON_ERROR));
        }
        [$disabledRequest, $disabled] = $this->metrics(false);
        ApiQueryMetrics::recordRoleCatalogRedisGet($disabledRequest, $event);
        self::assertArrayNotHasKey('role_catalog_redis_get', $disabled->summary());
        [, $fresh] = $this->metrics();
        self::assertSame(['observed' => false, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0], $fresh->summary()['role_catalog_redis_get']);
    }

    public function test_existing_redis_tracing_records_client_read_and_cpu_without_hiding_original_errors(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $tracing = new \App\Services\Monitoring\TracingService(new \OpenTelemetry\API\Trace\NoopTracerProvider);
        $event = new \Illuminate\Redis\Events\CommandExecuted('get', ['private-prefix:authorization_roles:v3'], 1.5, new class {
            public function getName(): string { return 'private-connection'; }
        });
        $tracing->recordRedis($event);
        self::assertSame(1, $metrics->summary()['processing_phases']['redis_tracing']['count']);
        self::assertSame(1, $metrics->summary()['role_catalog_redis_get']['count']);
        $failure = new \RuntimeException('original tracer failure');
        $event->connectionName = new class($failure) {
            public function __construct(private \RuntimeException $failure) {}
            public function __toString(): string { throw $this->failure; }
        };
        try {
            $tracing->recordRedis($event);
            self::fail('Original tracing error must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(2, $metrics->summary()['processing_phases']['redis_tracing']['count']);
        self::assertSame(2, $metrics->summary()['role_catalog_redis_get']['count']);
        [$request, $disabled] = $this->metrics(false);
        app()->instance('request', $request);
        $event->connectionName = 'private-connection';
        $tracing->recordRedis($event);
        self::assertArrayNotHasKey('processing_phases', $disabled->summary());
        self::assertArrayNotHasKey('role_catalog_redis_get', $disabled->summary());
    }

    public function test_redis_diagnostic_failure_does_not_prevent_original_tracing(): void
    {
        $request = Request::create('/api/v1/admin/example', 'GET');
        $request->attributes = new class extends \Symfony\Component\HttpFoundation\ParameterBag {
            public function get(string $key, mixed $default = null): mixed { throw new \RuntimeException('diagnostic failure'); }
        };
        app()->instance('request', $request);
        $name = new class {
            public int $calls = 0;
            public function __toString(): string { $this->calls++; return 'primary'; }
        };
        $event = new \Illuminate\Redis\Events\CommandExecuted('get', ['authorization_roles:v3'], 1.5, new class {
            public function getName(): string { return 'primary'; }
        });
        $event->connectionName = $name;
        (new \App\Services\Monitoring\TracingService(new \OpenTelemetry\API\Trace\NoopTracerProvider))->recordRedis($event);
        self::assertSame(1, $name->calls);
    }

    public function test_entity_build_diagnostics_keep_known_types_numeric_request_local_and_opt_in(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $value = new \stdClass;
        $calls = 0;
        self::assertSame($value, ApiQueryMetrics::measureProcessingPhase('rag_acl_entity_build', static function () use ($request, $value, &$calls): object {
            $calls++;
            ApiQueryMetrics::record($request, 2.5);

            return $value;
        }, 'project'));
        $failure = new \RuntimeException('private-operation-failure');
        try {
            ApiQueryMetrics::measureProcessingPhase('rag_acl_entity_build', static function () use ($failure): never { throw $failure; }, 'project');
            self::fail('The operation exception must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        foreach (['private_label', 'project:123', User::class, str_repeat('x', 1000)] as $label) {
            ApiQueryMetrics::measureProcessingPhase('rag_acl_entity_build', static fn (): null => null, $label);
        }
        ApiQueryMetrics::measureProcessingPhase('rag_prepare', static fn (): null => null, 'contract');
        $summary = $metrics->summary();
        self::assertArrayHasKey('rag_entity_builds', $summary);
        self::assertSame(['project'], array_column($summary['rag_entity_builds'], 'entity_type'));
        $build = $summary['rag_entity_builds'][0];
        self::assertSame(2, $build['count']);
        self::assertSame(1, $build['sql_count']);
        self::assertSame(2.5, $build['sql_total_ms']);
        self::assertSame(1, $calls);
        self::assertGreaterThanOrEqual(0, $build['total_ms']);
        foreach (array_diff_key($build, ['entity_type' => true]) as $number) { self::assertTrue(is_int($number) || is_float($number)); }
        $safe = (new SensitiveDataRedactor)->redact(['rag_entity_builds' => $summary['rag_entity_builds']]);
        self::assertSame($summary['rag_entity_builds'], $safe['rag_entity_builds']);
        self::assertStringNotContainsString('private', json_encode($summary, JSON_THROW_ON_ERROR));
        [$otherRequest, $otherMetrics] = $this->metrics();
        app()->instance('request', $otherRequest);
        self::assertSame([], $otherMetrics->summary()['rag_entity_builds']);
        [$disabledRequest, $disabled] = $this->metrics(false);
        app()->instance('request', $disabledRequest);
        self::assertSame($value, ApiQueryMetrics::measureProcessingPhase('rag_acl_entity_build', static fn (): object => $value, 'project'));
        self::assertArrayNotHasKey('rag_entity_builds', $disabled->summary());
    }

    public function test_entity_build_diagnostics_bound_storage_and_keep_complete_numeric_totals(): void
    {
        [$request, $metrics] = $this->metrics();
        $types = array_slice(array_keys(\App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog::entityDefinitions()), 0, 300);
        self::assertCount(300, $types);
        foreach ($types as $type) {
            $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
            ApiQueryMetrics::record($request, 1.25);
            ApiQueryMetrics::recordProcessingPhase($request, 'rag_acl_entity_build', $checkpoint['started_at'], $checkpoint, $type);
        }
        $summary = $metrics->summary();
        self::assertArrayHasKey('rag_entity_builds', $summary);
        $builds = $summary['rag_entity_builds'];
        self::assertCount(17, $builds);
        self::assertContains('__other__', array_column($builds, 'entity_type'));
        self::assertSame(300, array_sum(array_column($builds, 'count')));
        self::assertSame(300, array_sum(array_column($builds, 'sql_count')));
        self::assertSame(375.0, array_sum(array_column($builds, 'sql_total_ms')));
        $safe = (new SensitiveDataRedactor)->redact(['rag_entity_builds' => $builds]);
        self::assertSame(300, array_sum(array_column($safe['rag_entity_builds'], 'count')));
        self::assertSame(375.0, array_sum(array_column($safe['rag_entity_builds'], 'sql_total_ms')));
        self::assertLessThanOrEqual(256, count((new \ReflectionProperty($metrics, 'entityBuilds'))->getValue($metrics)));
        self::assertSame(300, $summary['processing_phases']['rag_acl_entity_build']['count']);
    }

    public function test_runtime_release_uses_valid_cached_config_without_source_capture_or_private_fallback(): void
    {
        $previousRelease = getenv('MOST_RELEASE_SHA');
        $hadConfig = app()->bound('config');
        $previousConfig = $hadConfig ? app('config') : null;
        $config = new \Illuminate\Config\Repository(['ai-assistant' => ['status_snapshot_release' => str_repeat('a', 40)]]);
        app()->instance('config', $config);
        try {
            putenv('MOST_RELEASE_SHA='.str_repeat('b', 40));
            [, $metrics] = $this->metrics();
            self::assertArrayHasKey('release_sha', $metrics->summary());
            self::assertSame(str_repeat('a', 40), $metrics->summary()['release_sha']);
            putenv('MOST_RELEASE_SHA');
            self::assertSame(str_repeat('a', 40), $metrics->summary()['release_sha']);
            $config->set('ai-assistant.status_snapshot_release', 'private-invalid-release');
            self::assertNull($metrics->summary()['release_sha']);
            $config->set('ai-assistant.status_snapshot_release', null);
            self::assertNull($metrics->summary()['release_sha']);
            putenv('MOST_RELEASE_SHA='.str_repeat('b', 40));
            self::assertSame(str_repeat('b', 40), $metrics->summary()['release_sha']);
            [, $disabled] = $this->metrics(false);
            self::assertSame(str_repeat('b', 40), $disabled->summary()['release_sha']);
            self::assertArrayNotHasKey('sql_sources', $disabled->summary());
            $config->set('ai-assistant.status_snapshot_release', 'private-invalid-release');
            self::assertNull($disabled->summary()['release_sha']);
            self::assertStringNotContainsString('private', json_encode($disabled->summary(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('private', json_encode($metrics->summary(), JSON_THROW_ON_ERROR));
        } finally {
            $previousRelease === false ? putenv('MOST_RELEASE_SHA') : putenv('MOST_RELEASE_SHA='.$previousRelease);
            if ($hadConfig) { app()->instance('config', $previousConfig); } else { app()->forgetInstance('config'); }
        }
    }

    public function test_security_emit_metrics_preserve_log_levels_redaction_alerts_and_entry_exception(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $previousFacadeApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(Container::getInstance());
        $channel = \Mockery::mock();
        $payload = ['cookie' => '[REDACTED]', 'safe' => 3];
        $channel->shouldReceive('log')->once()->with('warning', '[SECURITY] event.checked', $payload);
        $channel->shouldReceive('log')->once()->with('error', '[SECURITY] event.checked', $payload);
        $channel->shouldReceive('log')->once()->with('critical', '[SECURITY] event.checked', $payload);
        Log::shouldReceive('channel')->times(3)->with('security')->andReturn($channel);
        $logger = new class(new SafeLogWriter) extends SecurityLogger {
            public int $entries = 0;
            public int $alerts = 0;
            public ?\RuntimeException $failure = null;

            public function __construct(SafeLogWriter $writer) { $this->writer = $writer; }

            protected function createSecurityEntry(string $event, array $context, string $level): array
            {
                $this->entries++;
                if ($this->failure !== null) { throw $this->failure; }

                return $context;
            }

            protected function sendSecurityAlert(string $event, array $context, string $level): void { $this->alerts++; }
        };
        try {
            foreach (['INFO', 'warning', 'error', 'critical'] as $level) {
                $logger->log('event.checked', ['cookie' => 'private-cookie', 'safe' => 3], $level);
            }
            self::assertSame(3, $logger->entries);
            self::assertSame(2, $logger->alerts);
            self::assertSame(3, $metrics->summary()['processing_phases']['security_emit']['count']);
            self::assertSame(3, $metrics->summary()['processing_phases']['security_prepare']['count']);
            self::assertSame(3, $metrics->summary()['processing_phases']['security_write']['count']);
            $failure = new \RuntimeException('entry failure');
            $logger->failure = $failure;
            try {
                $logger->log('event.checked');
                self::fail('Entry exception must propagate.');
            } catch (\RuntimeException $actual) {
                self::assertSame($failure, $actual);
            }
            self::assertSame(4, $metrics->summary()['processing_phases']['security_emit']['count']);
            self::assertSame(4, $metrics->summary()['processing_phases']['security_prepare']['count']);
            self::assertSame(3, $metrics->summary()['processing_phases']['security_write']['count']);
        } finally {
            Log::clearResolvedInstance('log');
            Facade::setFacadeApplication($previousFacadeApp);
            \Mockery::close();
        }
    }

    public function test_security_phase_diagnostics_preserve_real_formatter_and_both_redaction_layers(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $previousFacadeApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(Container::getInstance());
        $stream = fopen('php://memory', 'w+');
        $handler = new \Monolog\Handler\StreamHandler($stream, \Monolog\Level::Warning);
        $handler->setFormatter(new \Monolog\Formatter\JsonFormatter);
        $monolog = new \Monolog\Logger('security', [$handler]);
        $channel = new \Illuminate\Log\Logger($monolog);
        (new \App\Services\Logging\EnsureLogFailuresAreNonFatal)($channel);
        $beforeProcessor = [];
        $monolog->pushProcessor(static function (\Monolog\LogRecord $record) use (&$beforeProcessor): \Monolog\LogRecord {
            $beforeProcessor[] = $record->context;

            return $record;
        });
        Log::shouldReceive('channel')->once()->with('security')->andReturn($channel);
        $logger = new class(new SafeLogWriter) extends SecurityLogger {
            public function __construct(SafeLogWriter $writer) { $this->writer = $writer; }
            protected function createSecurityEntry(string $event, array $context, string $level): array { return $context; }
        };
        try {
            $logger->log('event.checked', ['cookie' => 'private-cookie', 'note' => 'Bearer private-token', 'safe' => 3]);
            rewind($stream);
            $record = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
            $expected = ['cookie' => '[REDACTED]', 'note' => '[REDACTED]', 'safe' => 3];
            self::assertSame([$expected], $beforeProcessor);
            self::assertCount(2, $monolog->getProcessors());
            self::assertSame($expected, $record['context']);
            self::assertSame('[SECURITY] event.checked', $record['message']);
            self::assertSame('WARNING', $record['level_name']);
            foreach (['security_emit', 'security_prepare', 'security_write'] as $phase) {
                self::assertSame(1, $metrics->summary()['processing_phases'][$phase]['count']);
            }
            self::assertStringNotContainsString('private-', json_encode($metrics->summary(), JSON_THROW_ON_ERROR));
        } finally {
            Log::clearResolvedInstance('log');
            Facade::setFacadeApplication($previousFacadeApp);
            \Mockery::close();
            $handler->close();
            if (is_resource($stream)) { fclose($stream); }
        }
    }

    public function test_measured_operation_preserves_return_value_exception_and_opt_in_sql_deltas(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $calls = 0;
        $value = new \stdClass;
        $result = ApiQueryMetrics::measureProcessingPhase('rag_acl_entity_build', static function () use (&$calls, $request, $value): object {
            $calls++;
            ApiQueryMetrics::record($request, 2.5);

            return $value;
        });
        self::assertSame($value, $result);
        self::assertSame(1, $calls);
        $phase = $metrics->summary()['processing_phases']['rag_acl_entity_build'];
        self::assertSame(1, $phase['count']);
        self::assertSame(1, $phase['sql_count']);
        self::assertSame(2.5, $phase['sql_total_ms']);
        $failure = new \RuntimeException('expected failure');
        try {
            ApiQueryMetrics::measureProcessingPhase('rag_acl_register', static function () use ($failure): never { throw $failure; });
            self::fail('The operation exception must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(1, $metrics->summary()['processing_phases']['rag_acl_register']['count']);
        [$request, $disabled] = $this->metrics(false);
        app()->instance('request', $request);
        self::assertSame($value, ApiQueryMetrics::measureProcessingPhase('rag_acl_register', static fn (): object => $value));
        self::assertArrayNotHasKey('processing_phases', $disabled->summary());
        Container::setInstance(new Container);
        self::assertSame($value, ApiQueryMetrics::measureProcessingPhase('rag_acl_register', static fn (): object => $value));
    }

    public function test_access_subphases_preserve_fresh_roles_conditions_and_original_exception(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $resolver = $this->createMock(PermissionResolver::class);
        $resolver->method('forCurrentChecks')->willReturn($resolver);
        $resolver->method('hasPermission')->willReturn(true);
        $state = (object) ['roles' => true, 'condition' => true, 'failure' => null, 'reads' => 0];
        $service = new class($resolver, $state, $this->createMock(\App\Services\Logging\LoggingService::class)) extends AuthorizationService {
            public function __construct(PermissionResolver $resolver, private object $state, \App\Services\Logging\LoggingService $logging)
            {
                $this->permissionResolver = $resolver;
                $this->logging = $logging;
            }

            protected function resolveAuthContext(?array $context): ?\App\Domain\Authorization\Models\AuthorizationContext
            {
                return (new \App\Domain\Authorization\Models\AuthorizationContext)->forceFill([
                    'id' => 4, 'type' => 'project', 'resource_id' => 9, 'parent_context_id' => null,
                ]);
            }

            public function getUserRoles(User $user, ?\App\Domain\Authorization\Models\AuthorizationContext $context = null): \Illuminate\Support\Collection
            {
                $this->state->reads++;
                if ($this->state->failure !== null) { throw $this->state->failure; }

                return collect($this->state->roles ? [(new \App\Domain\Authorization\Models\UserRoleAssignment)->forceFill([
                    'context_id' => 4, 'role_slug' => 'private-role', 'role_type' => 'system',
                ])] : []);
            }

            protected function getContextHierarchy(\App\Domain\Authorization\Models\AuthorizationContext $context): \Illuminate\Support\Collection
            {
                return collect([$context]);
            }

            protected function evaluateConditions(\App\Domain\Authorization\Models\UserRoleAssignment $assignment, array $context): bool
            {
                return $this->state->condition;
            }
        };
        $user = (new User)->forceFill(['id' => 7, 'current_organization_id' => 38]);
        $context = ['project_id' => 9, 'organization_id' => 38];
        $previousFacadeApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(Container::getInstance());
        $cache = new class(new ArrayStore) extends Repository {
            public function driver(?string $name = null): self { return $this; }
        };
        $proof = $this->createMock(\App\Services\Billing\Bank\BankPaymentProofAccess::class);
        $proof->method('cacheDiscriminator')->willReturn('none');
        app()->instance(\App\Services\Billing\Bank\BankPaymentProofAccess::class, $proof);
        try {
            Cache::swap($cache);
            self::assertTrue($service->can($user, 'private.view', $context));
            self::assertSame([], $metrics->summary()['processing_phases']);
        } finally {
            Cache::clearResolvedInstance('cache');
            Facade::setFacadeApplication($previousFacadeApp);
        }
        $state->reads = 0;
        self::assertTrue($service->canCurrent($user, 'private.view', $context));
        $state->condition = false;
        self::assertFalse($service->canCurrent($user, 'private.view', $context));
        $state->roles = false;
        self::assertFalse($service->canCurrent($user, 'private.view', $context));
        $failure = new \RuntimeException('private-reader-failure');
        $state->failure = $failure;
        try {
            $service->canCurrent($user, 'private.view', $context);
            self::fail('Original role reader exception must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(4, $state->reads);
        $phases = $metrics->summary()['processing_phases'];
        self::assertSame(4, $phases['access_context_read']['count']);
        self::assertSame(4, $phases['access_role_read']['count']);
        self::assertSame(3, $phases['access_context_hierarchy']['count']);
        self::assertSame(2, $phases['access_conditions']['count']);
        self::assertStringNotContainsString('private', json_encode($phases, JSON_THROW_ON_ERROR));
    }

    public function test_access_evaluation_counts_actual_cache_misses_and_preserves_false_decisions_and_fresh_scope(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $resolver = \Mockery::mock(PermissionResolver::class);
        $resolver->shouldReceive('forCurrentChecks')->andReturnSelf();
        $resolver->shouldReceive('forReadScope')->andReturnSelf();
        $service = new class($resolver) extends AuthorizationService {
            public int $evaluations = 0;
            public bool $allowed = false;

            public function __construct(PermissionResolver $resolver) { $this->permissionResolver = $resolver; }

            protected function checkPermission(User $user, string $permission, ?array $context = null): bool
            {
                $this->evaluations++;

                return $this->allowed;
            }
        };
        $scope = $service->forCurrentChecks(true);
        $actor = new User;
        $actor->id = 71;
        foreach ([false, false] as $expected) {
            self::assertSame($expected, $scope->canCurrent($actor, 'projects.view', ['organization_id' => 1]));
        }
        self::assertSame(1, $scope->evaluations);
        $scope->allowed = true;
        self::assertFalse($scope->canCurrent($actor, 'projects.view', ['organization_id' => 1]));
        self::assertTrue($scope->forCurrentChecks(true)->canCurrent($actor, 'projects.view', ['organization_id' => 1]));
        $phases = $metrics->summary()['processing_phases'];
        self::assertSame(4, $phases['current_access_check']['count']);
        self::assertSame(2, $phases['current_access_evaluate']['count']);
        $reads = $metrics->summary()['access_reads'];
        self::assertSame(2, $reads['scope_memoized']['count']);
        self::assertSame(2, $reads['scope_memoized']['frames']);
        self::assertSame(2, $reads['scope_memoized']['stores']);
        self::assertSame(4, $reads['current_reuse']['count']);
        self::assertSame(2, $reads['current_reuse']['frames']);
        self::assertSame(2, $reads['current_reuse']['stores']);
        \Mockery::close();
    }

    public function test_numeric_access_and_acl_phases_survive_production_redaction(): void
    {
        [$request, $metrics] = $this->metrics();
        $phases = ['current_access_check', 'rag_acl_discovery', 'rag_acl_batch_compile', 'rag_acl_finish',
            'current_access_evaluate', 'rag_acl_entity_build', 'rag_acl_register',
            'access_system_match', 'access_module_match', 'security_emit',
            'access_context_read', 'access_role_read', 'access_context_hierarchy', 'access_conditions',
            'access_module_roles', 'access_module_entitlement', 'access_module_permission',
            'file_report_list', 'file_report_classify', 'file_report_access',
            'file_report_storage_exists', 'file_report_storage_url',
            'file_download_storage_exists', 'file_download_temporary_url'];
        foreach ($phases as $phase) {
            $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
            self::assertNotNull($checkpoint);
            ApiQueryMetrics::recordProcessingPhase($request, $phase, $checkpoint['started_at'], $checkpoint);
        }
        $summary = $metrics->summary();
        self::assertSame($phases, array_keys($summary['processing_phases']));
        $summary['authorization'] = 'Bearer private-token';
        $redacted = (new SensitiveDataRedactor)->redact($summary);
        self::assertSame($summary['processing_phases'], $redacted['processing_phases']);
        self::assertSame('[REDACTED]', $redacted['authorization']);
        self::assertArrayNotHasKey('authorization_current', $redacted['processing_phases']);
        foreach ($redacted['processing_phases'] as $phase) {
            self::assertSame(1, $phase['count']);
            self::assertSame(0, $phase['sql_count']);
            self::assertArrayNotHasKey('metrics', $phase);
        }
    }

    public function test_processing_phases_are_numeric_closed_request_local_and_opt_in(): void
    {
        $request = Request::create('/api/v1/admin/procurement/purchase-requests', 'GET');
        $metrics = new ApiQueryMetrics(true);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        ApiQueryMetrics::recordProcessingPhase($request, 'private-secret-phase', hrtime(true));
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', -1);
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', PHP_INT_MAX);
        self::assertSame([], $metrics->summary()['processing_phases']);
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', hrtime(true));
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', hrtime(true));
        $phase = $metrics->summary()['processing_phases']['list_prepare'];
        self::assertSame(2, $phase['count']);
        self::assertGreaterThanOrEqual(0, $phase['total_ms']);
        self::assertGreaterThanOrEqual(0, $phase['max_ms']);
        self::assertLessThanOrEqual($phase['total_ms'], $phase['max_ms']);
        self::assertStringNotContainsString('private-secret-phase', json_encode($metrics->summary(), JSON_THROW_ON_ERROR));
        $other = new ApiQueryMetrics;
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $other);
        ApiQueryMetrics::recordProcessingPhase($request, 'list_encode', hrtime(true));
        self::assertArrayNotHasKey('processing_phases', $other->summary());
        self::assertSame(0, $metrics->summary()['sql_count']);
        foreach (['journal_load', 'journal_prepare', 'journal_payload', 'journal_encode',
            'file_download_storage_exists', 'file_download_temporary_url',
            'design_freshness', 'design_resources', 'design_response_build', 'design_response_decode'] as $name) {
            ApiQueryMetrics::recordProcessingPhase($request, $name, hrtime(true));
            self::assertArrayNotHasKey('processing_phases', $other->summary());
            $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
            ApiQueryMetrics::recordProcessingPhase($request, $name, hrtime(true));
            self::assertSame(1, $metrics->summary()['processing_phases'][$name]['count']);
            self::assertGreaterThanOrEqual(0, $metrics->summary()['processing_phases'][$name]['total_ms']);
            $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $other);
        }
    }

    public function test_snapshot_metadata_accepts_only_the_closed_schema(): void
    {
        $metrics = new ApiQueryMetrics;
        $metrics->recordAssistantSnapshot(['phase' => 'private-value', 'section' => 'sources']);
        self::assertArrayNotHasKey('assistant_snapshot', $metrics->summary());
        $metrics->recordAssistantSnapshot(['phase' => 'snapshot_missing', 'section' => 'sources',
            'key_hash' => 'private-key', 'release_sha' => str_repeat('a', 40), 'age_seconds' => -1,
            'refresh_queued' => 'private-flag', 'actor_id' => 42, 'secret' => 'private-secret']);
        $snapshot = $metrics->summary()['assistant_snapshot'];
        self::assertNull($snapshot['key_hash']);
        self::assertNull($snapshot['age_seconds']);
        self::assertNull($snapshot['refresh_queued']);
        self::assertSame(str_repeat('a', 40), $snapshot['release_sha']);
        self::assertCount(8, $snapshot);
        self::assertStringNotContainsString('private-', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('actor_id', $snapshot);
        $metrics->recordAssistantSnapshotEpoch('private-phase', 'private-data');
        self::assertArrayNotHasKey('assistant_snapshot_epoch', $metrics->summary());
        $metrics->recordAssistantSnapshotEpoch('relation_mutation_present', 'ai_rag_sources');
        self::assertSame(['phase' => 'relation_mutation_present', 'relation' => 'ai_rag_sources'], $metrics->summary()['assistant_snapshot_epoch']);
        $metrics->recordAssistantSnapshotEpoch('schema_rejected', 'personal@example.test');
        self::assertSame(['phase' => 'schema_rejected', 'relation' => null], $metrics->summary()['assistant_snapshot_epoch']);
    }

    public function test_processing_sql_deltas_are_request_local_and_exclude_invalid_checkpoints(): void
    {
        [$request, $metrics] = $this->metrics();
        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($checkpoint);
        ApiQueryMetrics::record($request, 1.25);
        ApiQueryMetrics::record($request, 2.75);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_source_counts', $checkpoint['started_at'], $checkpoint);
        $next = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($next);
        ApiQueryMetrics::record($request, 3.5);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_source_counts', $next['started_at'], $next);
        $phase = $metrics->summary()['processing_phases']['rag_source_counts'];
        self::assertSame(2, $phase['count']);
        self::assertSame(3, $phase['sql_count']);
        self::assertSame(7.5, $phase['sql_total_ms']);
        self::assertGreaterThanOrEqual(0, $phase['total_ms']);
        self::assertArrayNotHasKey('metrics', $phase);
        self::assertArrayNotHasKey('started_at', $phase);
        foreach ([array_replace($checkpoint, ['sql_count' => 99]), array_replace($checkpoint, ['sql_total_ms' => NAN])] as $invalid) {
            ApiQueryMetrics::recordProcessingPhase($request, 'rag_prepare', $invalid['started_at'], $invalid);
        }
        self::assertArrayNotHasKey('rag_prepare', $metrics->summary()['processing_phases']);
        $other = new ApiQueryMetrics(true);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $other);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_source_counts', $checkpoint['started_at'], $checkpoint);
        self::assertSame([], $other->summary()['processing_phases']);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, new ApiQueryMetrics);
        self::assertNull(ApiQueryMetrics::processingCheckpoint($request));
    }

    public function test_processing_cpu_measurement_is_numeric_optional_and_does_not_include_wait_time(): void
    {
        [$request, $metrics] = $this->metrics();
        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($checkpoint);
        usleep(30_000);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_prepare', $checkpoint['started_at'], $checkpoint);
        $phase = $metrics->summary()['processing_phases']['rag_prepare'];
        self::assertGreaterThanOrEqual(25, $phase['total_ms']);
        if ($checkpoint['process_cpu_ms'] !== null) {
            self::assertIsFloat($phase['process_cpu_ms']);
            self::assertGreaterThanOrEqual(0, $phase['process_cpu_ms']);
            self::assertLessThan($phase['total_ms'] + 20, $phase['process_cpu_ms']);
        }
        $invalid = array_replace($checkpoint, ['process_cpu_ms' => NAN]);
        ApiQueryMetrics::recordProcessingPhase($request, 'role_catalog', $invalid['started_at'], $invalid);
        self::assertArrayNotHasKey('process_cpu_ms', $metrics->summary()['processing_phases']['role_catalog']);
    }

    public function test_role_catalog_diagnostics_preserve_result_and_exception_without_logging_catalog(): void
    {
        [$request, $metrics] = $this->metrics();
        $previousFacadeApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(Container::getInstance());
        try {
            $cache = new Repository(new ArrayStore);
            Cache::swap($cache);
            $catalog = collect(['private-role-fixture' => ['slug' => 'private-role-fixture']]);
            $cache->put('authorization_roles:v3', $catalog, 60);
            app()->instance('request', $request);
            self::assertSame($catalog, (new RoleScanner)->getAllRoles());
            $failure = $this->createMock(Repository::class);
            $failure->expects(self::once())->method('remember')->willThrowException(new \RuntimeException('catalog-read-fixture'));
            Cache::swap($failure);
            try {
                (new RoleScanner)->getAllRoles();
                self::fail('Expected catalog read exception');
            } catch (\RuntimeException $exception) {
                self::assertSame('catalog-read-fixture', $exception->getMessage());
            }
            $summary = $metrics->summary();
            self::assertSame(2, $summary['processing_phases']['role_catalog']['count']);
            self::assertSame(0, $summary['processing_phases']['role_catalog']['sql_count']);
            self::assertStringNotContainsString('private-role-fixture', json_encode($summary, JSON_THROW_ON_ERROR));
        } finally {
            Facade::clearResolvedInstance('cache');
            Facade::setFacadeApplication($previousFacadeApp);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        new Application(dirname(__DIR__, 3));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_source_aggregates_distinguish_variants_without_disclosing_sql_bindings_or_hashes(): void
    {
        [$request, $metrics] = $this->metrics();
        $sql = "select * from secret_table where token='private literal' and password=?";
        foreach (['private token', 'private token', 'personal@example.test'] as $binding) {
            $this->recordOne($request, new QueryExecuted($sql, [$binding], 2.5, new Connection(null)));
        }
        $summary = $metrics->summary();
        self::assertSame(3, $summary['sql_count']);
        self::assertCount(1, $summary['sql_sources']);
        $source = $summary['sql_sources'][0];
        self::assertSame(3, $source['count']);
        self::assertSame(7.5, $source['total_ms']);
        self::assertSame(2, $source['distinct_variants']);
        self::assertFalse($source['distinct_variants_capped']);
        self::assertSame('tests/Unit/Monitoring/ApiQuerySourceMetricsTest.php', $source['source']['file']);
        self::assertIsInt($source['source']['line']);
        $serialized = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach (['secret_table', 'private literal', 'private token', 'personal@example.test', hash('sha256', $sql), '"variants":', '"bindings":', '"args":'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $serialized);
        }
        self::assertStringNotContainsString(hash('sha256', $sql."\0".serialize(0).serialize('private token')."\0"), $serialized);
        [, $other] = $this->metrics();
        self::assertSame([], $other->summary()['sql_sources']);
    }

    public function test_source_diagnostics_are_opt_in_and_variant_storage_is_bounded(): void
    {
        [$request, $metrics] = $this->metrics(false);
        $this->recordOne($request, new QueryExecuted('select secret', ['private'], 1, new Connection(null)));
        self::assertArrayNotHasKey('sql_sources', $metrics->summary());
        [$request, $metrics] = $this->metrics();
        for ($i = 0; $i < 20; $i++) {
            $this->recordOne($request, new QueryExecuted('select ?', [$i], 1, new Connection(null)));
        }
        $source = $metrics->summary()['sql_sources'][0];
        self::assertSame(20, $source['count']);
        self::assertSame(16, $source['distinct_variants']);
        self::assertTrue($source['distinct_variants_capped']);
        $this->recordOne($request, new QueryExecuted('select ?', [new \stdClass], 1, new Connection(null)));
        self::assertSame(1, $metrics->summary()['sql_sources'][0]['unclassified_variants']);
    }

    public function test_source_size_maxima_include_unclassified_queries_without_reading_binding_values(): void
    {
        [$request, $metrics] = $this->metrics();
        $opaque = new class
        {
            public int $calls = 0;

            public function __toString(): string
            {
                $this->calls++;

                throw new \RuntimeException('private binding must not be inspected');
            }
        };
        $privateSql = "select 'private literal' where token=?";
        foreach ([[$privateSql, ['private token'], 1.25], [str_repeat('x', 40000), [], 2.5],
            ['select ?', array_fill(0, 1025, $opaque), 4.5], ['select ?', [$opaque], 1.0], ['select ?', [], 0.0]] as [$sql, $bindings, $time]) {
            $this->recordOne($request, new QueryExecuted($sql, $bindings, $time, new Connection(null)));
        }
        $summary = $metrics->summary();
        self::assertCount(1, $summary['sql_sources']);
        $source = $summary['sql_sources'][0];
        self::assertArrayHasKey('sql_bytes_max', $source);
        self::assertArrayHasKey('bindings_max', $source);
        self::assertSame(40000, $source['sql_bytes_max']);
        self::assertSame(1025, $source['bindings_max']);
        self::assertSame(5, $source['count']);
        self::assertSame(9.25, $source['total_ms']);
        self::assertSame(4.5, $source['max_ms']);
        self::assertSame(3, $source['unclassified_variants']);
        self::assertSame(2, $source['distinct_variants']);
        self::assertSame(0, $opaque->calls);
        self::assertSame(0, $summary['sql_sources_dropped_count']);
        $serialized = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach ([$privateSql, 'private literal', 'private token', '"bindings":', '"variants":'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $serialized);
        }
    }

    public function test_source_size_maxima_remain_local_to_each_source(): void
    {
        [$request, $metrics] = $this->metrics();
        $sql = "select 'тест' as marker";
        $this->recordOne($request, new QueryExecuted($sql, ['private', 'values'], 2, new Connection(null)));
        $this->recordTwo($request, new QueryExecuted('select ?', array_fill(0, 7, 'private'), 3, new Connection(null)));
        $sources = $metrics->summary()['sql_sources'];
        self::assertCount(2, $sources);
        self::assertSame([strlen($sql), 8], array_column($sources, 'sql_bytes_max'));
        self::assertSame([2, 7], array_column($sources, 'bindings_max'));
        self::assertSame([1, 1], array_column($sources, 'count'));
        self::assertSame([2.0, 3.0], array_column($sources, 'total_ms'));
    }

    public function test_source_group_overflow_preserves_complete_request_totals(): void
    {
        [$request, $metrics] = $this->metrics();
        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($checkpoint);
        $queries = ['select * from role_conditions', 'select * from authorization_contexts', 'select * from pg_attribute a',
            'select count(*) as stored_count from records', 'select * from ai_rag_sources', 'select * from files', 'SET LOCAL statement_timeout=1', 'select 1'];
        foreach ($queries as $sql) {
            $event = new QueryExecuted($sql, [], 1.5, new Connection(null));
            $this->recordOne($request, $event);
            $this->recordTwo($request, $event);
            $this->recordThree($request, $event);
            $this->recordFour($request, $event);
            $this->recordFive($request, $event);
        }
        self::assertCount(40, $metrics->summary()['sql_sources']);
        self::assertSame(0, $metrics->summary()['sql_sources_dropped_count']);
        foreach ($queries as $sql) {
            $event = new QueryExecuted($sql, [], 1.5, new Connection(null));
            $this->recordSix($request, $event);
            $this->recordSeven($request, $event);
            $this->recordEight($request, $event);
            $this->recordNine($request, $event);
        }
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_expected_counts', $checkpoint['started_at'], $checkpoint);
        $summary = $metrics->summary();
        self::assertSame(72, $summary['sql_count']);
        self::assertSame(108.0, $summary['sql_total_ms']);
        self::assertCount(64, $summary['sql_sources']);
        self::assertSame(8, $summary['sql_sources_dropped_count']);
        self::assertSame(64, array_sum(array_column($summary['sql_sources'], 'count')));
        self::assertSame(72, $summary['processing_phases']['rag_expected_counts']['sql_count']);
        self::assertSame(108.0, $summary['processing_phases']['rag_expected_counts']['sql_total_ms']);
    }

    public function test_diagnostic_failure_preserves_query_totals_and_does_not_escape(): void
    {
        Container::setInstance(new Container);
        [$request, $metrics] = $this->metrics();
        $this->recordOne($request, new QueryExecuted('select 1', [], 3.5, new Connection(null)));
        $summary = $metrics->summary();
        self::assertSame(1, $summary['sql_count']);
        self::assertSame(3.5, $summary['sql_total_ms']);
        self::assertSame([], $summary['sql_sources']);
        self::assertSame(1, $summary['sql_sources_dropped_count']);
    }

    public function test_variant_input_limits_reject_oversized_values_without_calling_object_methods(): void
    {
        [$request, $metrics] = $this->metrics();
        $date = new class('2026-10-07') extends \DateTimeImmutable
        {
            public int $formatCalls = 0;

            public function format(string $format): string
            {
                $this->formatCalls++;

                return str_repeat('private', 100000);
            }
        };
        foreach ([['select ?', [$date]], [str_repeat('x', 32769), []], ['select ?', [str_repeat('x', 4097)]],
            ['select ?', array_fill(0, 1025, 1)], [str_repeat('x', 32768), array_fill(0, 9, str_repeat('x', 4096))]] as [$sql, $bindings]) {
            $this->recordOne($request, new QueryExecuted($sql, $bindings, 1, new Connection(null)));
        }
        self::assertSame(0, $date->formatCalls);
        $source = $metrics->summary()['sql_sources'][0];
        self::assertSame(5, $source['unclassified_variants']);
        self::assertSame(0, $source['distinct_variants']);
        self::assertSame(5, $source['count']);
    }

    public function test_project_pulse_phases_remain_numeric_opt_in_and_request_local(): void
    {
        [$request, $metrics] = $this->metrics();
        foreach (['pulse_actor', 'pulse_current', 'pulse_facts', 'pulse_access', 'pulse_rules',
            'pulse_synthesis', 'pulse_finance', 'pulse_write', 'pulse_format'] as $name) {
            $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
            self::assertNotNull($checkpoint);
            ApiQueryMetrics::record($request, 2.5);
            ApiQueryMetrics::recordProcessingPhase($request, $name, $checkpoint['started_at'], $checkpoint);
            $phase = $metrics->summary()['processing_phases'][$name];
            self::assertSame(1, $phase['count']);
            self::assertSame(1, $phase['sql_count']);
            self::assertSame(2.5, $phase['sql_total_ms']);
            self::assertGreaterThanOrEqual(0, $phase['total_ms']);
            self::assertArrayNotHasKey('metrics', $phase);
        }
        ApiQueryMetrics::recordProcessingPhase($request, 'pulse_private-summary', hrtime(true));
        self::assertStringNotContainsString('private', json_encode($metrics->summary(), JSON_THROW_ON_ERROR));
        [$otherRequest, $otherMetrics] = $this->metrics(false);
        ApiQueryMetrics::recordProcessingPhase($otherRequest, 'pulse_facts', hrtime(true));
        self::assertArrayNotHasKey('processing_phases', $otherMetrics->summary());
        self::assertSame(0, $otherMetrics->summary()['sql_count']);
        self::assertSame(9, $metrics->summary()['sql_count']);
    }

    public function test_rag_batch_metrics_preserve_result_sql_deltas_and_closed_candidate_pairs(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        ApiQueryMetrics::record($request, 8.0);
        $result = new \stdClass;
        $identities = ['assistant_document' => ['file_document' => true], 'estimate' => ['estimate' => true, 'file_document' => true],
            'estimate_template' => ['estimate_reference' => true], 'private-entity' => ['private-source' => new \stdClass]];
        self::assertSame($result, ApiQueryMetrics::measureRagSourceBatch($identities, false, static function () use ($request, $result): object {
            ApiQueryMetrics::record($request, 2.5);

            return $result;
        }));
        $summary = $metrics->summary();
        $batch = $summary['rag_source_batches'][0];
        self::assertSame(1, $batch['ordinal']);
        self::assertSame('stored', $batch['mode']);
        self::assertTrue($batch['completed']);
        self::assertFalse($batch['candidate_identities_complete']);
        self::assertSame([
            ['entity_type' => 'assistant_document', 'source_type' => 'file_document'],
            ['entity_type' => 'estimate', 'source_type' => 'estimate'],
            ['entity_type' => 'estimate_template', 'source_type' => 'estimate_reference'],
        ], $batch['candidate_identities']);
        self::assertSame(1, $batch['sql_count']);
        self::assertSame(2.5, $batch['sql_total_ms']);
        self::assertGreaterThanOrEqual(0, $batch['duration_ms']);
        if (isset($batch['process_cpu_ms'])) { self::assertGreaterThanOrEqual(0, $batch['process_cpu_ms']); }
        self::assertSame(2, $summary['sql_count']);
        self::assertSame(10.5, $summary['sql_total_ms']);
        self::assertSame(0, $summary['rag_source_batches_dropped_count']);
        self::assertSame($summary['rag_source_batches'], (new SensitiveDataRedactor)->redact($summary)['rag_source_batches']);
        $encoded = json_encode($summary['rag_source_batches'], JSON_THROW_ON_ERROR);
        foreach (['private', 'metrics', 'started_at', 'bindings', 'organization_id', 'entity_id'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_rag_batch_metrics_bound_records_candidate_pairs_and_input_work(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $identities = [];
        $definitions = \App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog::entityDefinitions();
        foreach (array_slice($definitions, 0, 40, true) as $entity => $definition) { $identities[$entity] = [$definition[0] => true]; }
        self::assertCount(40, $identities);
        for ($index = 1; $index <= 20; $index++) {
            self::assertSame($index, ApiQueryMetrics::measureRagSourceBatch($identities, false, static function () use ($request, $index): int {
                ApiQueryMetrics::record($request, 0.25);

                return $index;
            }));
        }
        $summary = $metrics->summary();
        self::assertCount(16, $summary['rag_source_batches']);
        self::assertSame(range(1, 16), array_column($summary['rag_source_batches'], 'ordinal'));
        self::assertSame(4, $summary['rag_source_batches_dropped_count']);
        self::assertSame(20, $summary['sql_count']);
        self::assertSame(5.0, $summary['sql_total_ms']);
        foreach ($summary['rag_source_batches'] as $batch) {
            self::assertCount(32, $batch['candidate_identities']);
            self::assertFalse($batch['candidate_identities_complete']);
            self::assertSame(1, $batch['sql_count']);
        }
        self::assertLessThan(100000, strlen(json_encode($summary['rag_source_batches'], JSON_THROW_ON_ERROR)));
        [$nextRequest, $nextMetrics] = $this->metrics();
        app()->instance('request', $nextRequest);
        $invalid = array_fill_keys(array_map(static fn (int $index): string => 'private-'.$index, range(0, 999)), []);
        ApiQueryMetrics::measureRagSourceBatch($invalid, false, static fn (): null => null);
        self::assertSame([], $nextMetrics->summary()['rag_source_batches'][0]['candidate_identities']);
        self::assertFalse($nextMetrics->summary()['rag_source_batches'][0]['candidate_identities_complete']);
        self::assertStringNotContainsString('private', json_encode($nextMetrics->summary(), JSON_THROW_ON_ERROR));
    }

    public function test_rag_batch_metrics_are_opt_in_request_local_and_reject_replaced_metrics(): void
    {
        [$request, $disabled] = $this->metrics(false);
        app()->instance('request', $request);
        $calls = 0;
        $operation = static function () use (&$calls): int { return ++$calls; };
        self::assertSame(1, ApiQueryMetrics::measureRagSourceBatch([], false, $operation));
        self::assertArrayNotHasKey('rag_source_batches', $disabled->summary());
        $request->attributes->remove(ApiQueryMetrics::REQUEST_ATTRIBUTE);
        self::assertSame(2, ApiQueryMetrics::measureRagSourceBatch([], false, $operation));
        [$currentRequest, $currentMetrics] = $this->metrics();
        app()->instance('request', $currentRequest);
        self::assertSame([], $currentMetrics->summary()['rag_source_batches']);
        $replacement = new ApiQueryMetrics(true);
        self::assertSame(3, ApiQueryMetrics::measureRagSourceBatch(['project' => ['project' => true]], false,
            static function () use ($currentRequest, $replacement, $operation): int {
                $currentRequest->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $replacement);

                return $operation();
            }));
        self::assertSame([], $currentMetrics->summary()['rag_source_batches']);
        self::assertSame([], $replacement->summary()['rag_source_batches']);
    }

    public function test_rag_batch_metrics_preserve_nested_order_and_original_exception(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $failure = new \RuntimeException('private-operation-failure');
        $pairs = ['project' => ['project' => true]];
        try {
            ApiQueryMetrics::measureRagSourceBatch($pairs, true, static fn () => ApiQueryMetrics::measureRagSourceBatch($pairs, false,
                static function () use ($request, $failure): never {
                    ApiQueryMetrics::record($request, 3.0);
                    throw $failure;
                }));
            self::fail('The original consumer exception must propagate.');
        } catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        $batches = $metrics->summary()['rag_source_batches'];
        self::assertSame([1, 2], array_column($batches, 'ordinal'));
        self::assertSame(['expected', 'stored'], array_column($batches, 'mode'));
        self::assertSame([false, false], array_column($batches, 'completed'));
        self::assertSame([true, true], array_column($batches, 'candidate_identities_complete'));
        self::assertSame([1, 1], array_column($batches, 'sql_count'));
        self::assertSame([3.0, 3.0], array_column($batches, 'sql_total_ms'));
        self::assertStringNotContainsString('private', json_encode($batches, JSON_THROW_ON_ERROR));
    }

    private function metrics(bool $capture = true): array
    {
        $request = Request::create('/api/v1/admin/ai-assistant/conversations', 'GET');
        $metrics = new ApiQueryMetrics($capture);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);

        return [$request, $metrics];
    }

    private function recordOne(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordTwo(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordThree(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordFour(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordFive(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordSix(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordSeven(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordEight(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordNine(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }
}
