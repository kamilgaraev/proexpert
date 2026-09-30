<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantReadPermitTimeoutException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AssistantReadConcurrencyLimiterTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_full_checks_run_during_admission_and_narrow_guard_renews_the_acquired_lease(): void
    {
        $redis = $this->redis([0, 1]);
        $fullChecks = 0;
        $narrowChecks = 0;
        $result = (new AssistantReadConcurrencyLimiter)->run(function (callable $heartbeat) use ($redis): string {
            $redis->events[] = 'read';
            $heartbeat();

            return 'unchanged';
        }, function () use (&$fullChecks, $redis): void {
            $fullChecks++;
            $redis->events[] = 'full';
        }, 38, function () use (&$narrowChecks, $redis): void {
            $narrowChecks++;
            $redis->events[] = 'narrow';
        });

        $this->assertSame('unchanged', $result);
        $this->assertSame(2, $fullChecks);
        $this->assertSame(3, $narrowChecks);
        $this->assertSame(['full', 'enqueue', 'acquire', 'full', 'acquire', 'narrow', 'renew', 'read', 'narrow', 'narrow', 'renew', 'release'], $redis->events);
    }

    public function test_burst_guards_run_each_time_but_lease_renewal_is_bounded_and_final_check_is_forced(): void
    {
        $redis = $this->redis();
        $clock = (object) ['now' => 0];
        $guardChecks = 0;
        $limiter = new AssistantReadConcurrencyLimiter('test:', static fn (): int => $clock->now);

        $limiter->run(function (callable $heartbeat) use (&$guardChecks, $clock): void {
            for ($index = 0; $index < 20; $index++) {
                $heartbeat();
            }
            $clock->now = 5_000_000_000;
            $heartbeat();
        }, static fn (): null => null, 38, function () use (&$guardChecks): void {
            $guardChecks++;
        });

        $this->assertSame(23, $guardChecks);
        $this->assertSame(3, count(array_filter($redis->events, static fn (string $event): bool => $event === 'renew')));
        $this->assertSame('release', end($redis->events));
    }

    public function test_denial_on_any_burst_guard_stops_the_read_and_releases_the_permit(): void
    {
        $redis = $this->redis();
        $guardChecks = 0;
        $failure = new AuthorizationException;

        try {
            (new AssistantReadConcurrencyLimiter)->run(function (callable $heartbeat): never {
                for ($index = 0; $index < 10; $index++) {
                    $heartbeat();
                }
                throw new RuntimeException('guard_should_interrupt_first');
            }, static fn (): null => null, 38, function () use (&$guardChecks, $failure): void {
                if (++$guardChecks === 4) {
                    throw $failure;
                }
            });
            $this->fail('A denied per-callback guard must stop the read.');
        } catch (AuthorizationException $actual) {
            $this->assertSame($failure, $actual);
        }

        $this->assertSame(4, $guardChecks);
        $this->assertSame('release', end($redis->events));
    }

    public function test_failed_interval_renewal_fails_closed_and_releases_the_permit(): void
    {
        $redis = $this->redis([1], [1, 0]);
        $clock = (object) ['now' => 0];
        $guardChecks = 0;
        $limiter = new AssistantReadConcurrencyLimiter('test:', static fn (): int => $clock->now);

        try {
            $limiter->run(function (callable $heartbeat) use ($clock): void {
                $clock->now = 5_000_000_000;
                $heartbeat();
            }, static fn (): null => null, 38, function () use (&$guardChecks): void {
                $guardChecks++;
            });
            $this->fail('A lease lost at the renewal boundary must stop the read.');
        } catch (AssistantReadPermitTimeoutException $failure) {
            $this->assertSame('assistant_read_permit_lease_lost', $failure->getMessage());
        }

        $this->assertSame(2, $guardChecks);
        $this->assertSame(['enqueue', 'acquire', 'renew', 'renew', 'release'], $redis->events);
    }

    public function test_failed_forced_final_renewal_blocks_result_publication_and_releases_the_permit(): void
    {
        $redis = $this->redis([1], [1, 0]);
        $readExecuted = false;

        try {
            (new AssistantReadConcurrencyLimiter)->run(static function () use (&$readExecuted): string {
                $readExecuted = true;

                return 'must_not_publish';
            }, static fn (): null => null, 38, static fn (): null => null);
            $this->fail('A failed final ownership check must block publication.');
        } catch (AssistantReadPermitTimeoutException $failure) {
            $this->assertSame('assistant_read_permit_lease_lost', $failure->getMessage());
        }

        $this->assertTrue($readExecuted);
        $this->assertSame(['enqueue', 'acquire', 'renew', 'renew', 'release'], $redis->events);
    }

    public function test_monotonic_read_deadline_expires_even_when_burst_renewal_is_skipped(): void
    {
        $redis = $this->redis();
        $clock = (object) ['now' => 0];
        $guardChecks = 0;
        $limiter = new AssistantReadConcurrencyLimiter('test:', static fn (): int => $clock->now);

        try {
            $limiter->run(function (callable $heartbeat) use ($clock): void {
                $clock->now = 30_000_000_000;
                $heartbeat();
            }, static fn (): null => null, 38, function () use (&$guardChecks): void {
                $guardChecks++;
            });
            $this->fail('The monotonic phase deadline must expire before another lease renewal.');
        } catch (AssistantReadPermitTimeoutException $failure) {
            $this->assertSame('assistant_read_phase_deadline_exceeded', $failure->getMessage());
        }

        $this->assertSame(2, $guardChecks);
        $this->assertSame(['enqueue', 'acquire', 'renew', 'release'], $redis->events);
    }

    public function test_each_read_scope_acquires_and_renews_a_fresh_token(): void
    {
        $redis = $this->redis([1, 1], [1, 1, 1, 1]);
        $clock = (object) ['now' => 0];
        $limiter = new AssistantReadConcurrencyLimiter('test:', static fn (): int => $clock->now);

        $limiter->run(static fn (): null => null, static fn (): null => null, 38);
        $clock->now += 1_000_000_000;
        $limiter->run(static fn (): null => null, static fn (): null => null, 39);

        $this->assertSame(2, count($redis->arguments['acquire']));
        $this->assertSame(4, count($redis->arguments['renew']));
        $this->assertNotSame($redis->arguments['renew'][0][2], $redis->arguments['renew'][2][2]);
        $this->assertSame(2, count(array_filter($redis->events, static fn (string $event): bool => $event === 'release')));
    }

    public function test_initial_admission_has_only_one_full_checkpoint(): void
    {
        $redis = $this->redis();
        $fullChecks = 0;

        (new AssistantReadConcurrencyLimiter)->run(
            static fn (): null => null,
            function () use (&$fullChecks): void {
                $fullChecks++;
            },
            38,
            static fn (): null => null,
        );

        $this->assertSame(1, $fullChecks);
        $this->assertSame(['enqueue', 'acquire', 'renew', 'renew', 'release'], $redis->events);
    }

    public function test_cancellation_precedes_redis_connection_resolution(): void
    {
        $connectionAttempts = 0;
        Redis::swap(new class($connectionAttempts)
        {
            public function __construct(private int &$connectionAttempts) {}

            public function connection(?string $name = null): never
            {
                $this->connectionAttempts++;
                throw new RuntimeException('redis_unavailable');
            }
        });
        $failure = new AssistantRequestCancelled;

        try {
            (new AssistantReadConcurrencyLimiter)->run(
                static fn (): null => null,
                static fn () => throw $failure,
                38,
            );
            $this->fail('Cancellation must precede Redis access.');
        } catch (AssistantRequestCancelled $actual) {
            $this->assertSame($failure, $actual);
        }

        $this->assertSame(0, $connectionAttempts);
    }

    public function test_revocation_after_an_admission_wait_runs_a_fresh_full_checkpoint(): void
    {
        $redis = $this->redis([0, 1]);
        $fullChecks = 0;
        $failure = new AuthorizationException;

        try {
            (new AssistantReadConcurrencyLimiter)->run(
                fn () => $this->fail('A revoked request cannot run the read.'),
                function () use (&$fullChecks, $failure): void {
                    if (++$fullChecks === 2) {
                        throw $failure;
                    }
                },
                38,
                fn () => $this->fail('A request without a permit cannot use the narrow guard.'),
            );
            $this->fail('Revocation must interrupt admission.');
        } catch (AuthorizationException $actual) {
            $this->assertSame($failure, $actual);
        }

        $this->assertSame(2, $fullChecks);
        $this->assertSame(['enqueue', 'acquire', 'release'], $redis->events);
    }

    public function test_omitting_the_optional_guard_preserves_existing_full_heartbeat_behavior(): void
    {
        $redis = $this->redis();
        $checks = 0;
        (new AssistantReadConcurrencyLimiter)->run(static function (callable $heartbeat): void {
            $heartbeat();
        }, function () use (&$checks): void {
            $checks++;
        }, 38);

        $this->assertSame(4, $checks);
        $this->assertSame(2, count(array_filter($redis->events, static fn (string $event): bool => $event === 'renew')));
        $this->assertSame('release', end($redis->events));
    }

    public function test_cancellation_during_admission_never_executes_the_read_or_narrow_guard(): void
    {
        $redis = $this->redis();
        $failure = new AssistantRequestCancelled;
        try {
            (new AssistantReadConcurrencyLimiter)->run(
                fn () => $this->fail('A cancelled request cannot execute a read.'),
                static fn () => throw $failure,
                38,
                fn () => $this->fail('A request without a permit cannot invoke its read guard.'),
            );
            $this->fail('Cancellation must propagate.');
        } catch (AssistantRequestCancelled $actual) {
            $this->assertSame($failure, $actual);
        }
        $this->assertSame([], $redis->events);
    }

    public function test_expired_effective_budget_after_read_blocks_return_and_releases_the_permit(): void
    {
        $redis = $this->redis();
        $failure = new AssistantRequestDeadlineExceeded;
        $guards = 0;
        $readExecuted = false;
        try {
            (new AssistantReadConcurrencyLimiter)->run(function () use (&$readExecuted): string {
                $readExecuted = true;

                return 'must_not_return';
            }, static fn () => null, 38, function () use (&$guards, $failure): void {
                if (++$guards === 2) {
                    throw $failure;
                }
            });
            $this->fail('An expired read cannot return its result.');
        } catch (AssistantRequestDeadlineExceeded $actual) {
            $this->assertSame($failure, $actual);
        }
        $this->assertTrue($readExecuted);
        $this->assertSame(['enqueue', 'acquire', 'renew', 'release'], $redis->events);
    }

    public function test_lost_lease_still_blocks_read_with_the_narrow_guard(): void
    {
        $redis = $this->redis([1], [0]);
        $guards = 0;
        try {
            (new AssistantReadConcurrencyLimiter)->run(
                fn () => $this->fail('A lost lease cannot execute a read.'),
                static fn () => null,
                38,
                function () use (&$guards): void {
                    $guards++;
                },
            );
            $this->fail('Losing the lease must propagate.');
        } catch (AssistantReadPermitTimeoutException $failure) {
            $this->assertSame('assistant_read_permit_lease_lost', $failure->getMessage());
        }
        $this->assertSame(1, $guards);
        $this->assertSame(['enqueue', 'acquire', 'renew', 'release'], $redis->events);
    }

    public function test_wait_and_hold_time_are_reported_separately(): void
    {
        $redis = $this->redis([0, 1]);
        $clock = (object) ['now' => 0];
        $limiter = new AssistantReadConcurrencyLimiter('test:', static fn (): int => $clock->now);
        $checkpoints = 0;
        $measurements = [];

        $result = $limiter->run(
            function (callable $heartbeat) use ($clock): string {
                $clock->now += 75_000_000;
                $heartbeat();

                return 'unchanged';
            },
            function () use (&$checkpoints, $clock): void {
                if (++$checkpoints === 2) {
                    $clock->now += 250_000_000;
                }
            },
            38,
            null,
            function (string $phase, float $durationMs, bool $success, ?string $exceptionClass) use (&$measurements, $redis): void {
                $measurements[$phase] = [
                    'duration_ms' => $durationMs,
                    'success' => $success,
                    'exception_class' => $exceptionClass,
                ];
                $redis->events[] = 'timing_'.$phase;
            },
        );

        $this->assertSame('unchanged', $result);
        $this->assertSame(250.0, $measurements['read_permit_wait']['duration_ms']);
        $this->assertSame(75.0, $measurements['read_permit_hold']['duration_ms']);
        $this->assertTrue($measurements['read_permit_wait']['success']);
        $this->assertTrue($measurements['read_permit_hold']['success']);
        $this->assertSame(['release', 'timing_read_permit_wait', 'timing_read_permit_hold'], array_slice($redis->events, -3));
    }

    public function test_failed_read_reports_hold_failure_and_releases_permit(): void
    {
        $redis = $this->redis();
        $clock = (object) ['now' => 0];
        $limiter = new AssistantReadConcurrencyLimiter('test:', static fn (): int => $clock->now);
        $failure = new RuntimeException('private_read_failure_fixture');
        $measurements = [];

        try {
            $limiter->run(
                function (callable $heartbeat) use ($clock, $failure): never {
                    $clock->now += 35_000_000;
                    throw $failure;
                },
                static fn (): null => null,
                38,
                null,
                function (string $phase, float $durationMs, bool $success, ?string $exceptionClass) use (&$measurements, $redis): void {
                    $measurements[$phase] = [
                        'duration_ms' => $durationMs,
                        'success' => $success,
                        'exception_class' => $exceptionClass,
                    ];
                    $redis->events[] = 'timing_'.$phase;
                },
            );
            $this->fail('The original read failure must propagate.');
        } catch (RuntimeException $actual) {
            $this->assertSame($failure, $actual);
        }

        $this->assertSame(35.0, $measurements['read_permit_hold']['duration_ms']);
        $this->assertFalse($measurements['read_permit_hold']['success']);
        $this->assertSame(RuntimeException::class, $measurements['read_permit_hold']['exception_class']);
        $this->assertSame(['release', 'timing_read_permit_wait', 'timing_read_permit_hold'], array_slice($redis->events, -3));
        $this->assertStringNotContainsString('private_read_failure_fixture', json_encode($measurements, JSON_THROW_ON_ERROR));
    }

    private function redis(array $admissions = [1], array $renewals = [1, 1, 1]): object
    {
        $reflection = new ReflectionClass(AssistantReadConcurrencyLimiter::class);
        $scripts = [];
        foreach (['ENQUEUE_SCRIPT' => 'enqueue', 'TRY_ACQUIRE_SCRIPT' => 'acquire', 'RENEW_SCRIPT' => 'renew', 'RELEASE_SCRIPT' => 'release'] as $constant => $event) {
            $scripts[$reflection->getConstant($constant)] = $event;
        }
        $redis = new class($scripts, $admissions, $renewals)
        {
            public array $events = [];

            public array $arguments = [];

            public function __construct(private readonly array $scripts, private array $admissions, private array $renewals) {}

            public function eval(string $script, mixed ...$arguments): int
            {
                $event = $this->scripts[$script];
                $this->events[] = $event;
                $this->arguments[$event][] = $arguments;

                return match ($event) {
                    'acquire' => array_shift($this->admissions) ?? 1,
                    'renew' => array_shift($this->renewals) ?? 1,
                    default => 1,
                };
            }
        };
        Redis::swap(new class($redis)
        {
            public function __construct(private readonly object $redis) {}

            public function connection(?string $name = null): object
            {
                return $this->redis;
            }
        });

        return $redis;
    }
}
