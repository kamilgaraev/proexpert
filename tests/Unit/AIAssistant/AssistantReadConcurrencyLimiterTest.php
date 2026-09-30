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
        $this->assertSame(['full', 'enqueue', 'acquire', 'full', 'acquire', 'narrow', 'renew', 'read', 'narrow', 'renew', 'narrow', 'renew', 'release'], $redis->events);
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
        $this->assertSame(3, count(array_filter($redis->events, static fn (string $event): bool => $event === 'renew')));
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

            public function __construct(private readonly array $scripts, private array $admissions, private array $renewals) {}

            public function eval(string $script, mixed ...$arguments): int
            {
                $event = $this->scripts[$script];
                $this->events[] = $event;

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
