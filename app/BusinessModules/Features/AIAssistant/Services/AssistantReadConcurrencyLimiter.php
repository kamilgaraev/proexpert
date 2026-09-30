<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

final class AssistantReadConcurrencyLimiter
{
    private const CAPACITY = 2;
    private const PER_ORGANIZATION_CAPACITY = 1;
    private const ADMISSION_TIMEOUT_MS = 12_000;
    private const READ_PHASE_DEADLINE_MS = 30_000;
    private const LEASE_TTL_MS = 60_000;
    private const POLL_INTERVAL_US = 100_000;
    private const STALE_WAITER_TTL_MS = 15_000;

    private const ENQUEUE_SCRIPT = <<<'LUA'
local expired_active = redis.call('ZRANGEBYSCORE', KEYS[5], '-inf', ARGV[3])
for _, token in ipairs(expired_active) do
    redis.call('ZREM', KEYS[5], token)
    redis.call('HDEL', KEYS[6], token)
end
local stale_waiters = redis.call('ZRANGE', KEYS[2], 0, -1)
for _, token in ipairs(stale_waiters) do
    local since = tonumber(redis.call('HGET', KEYS[4], token) or '0')
    if since == 0 or ARGV[3] - since > tonumber(ARGV[4]) then
        redis.call('ZREM', KEYS[2], token)
        redis.call('HDEL', KEYS[3], token)
        redis.call('HDEL', KEYS[4], token)
    end
end
local ticket = redis.call('INCR', KEYS[1])
redis.call('ZADD', KEYS[2], ticket, ARGV[1])
redis.call('HSET', KEYS[3], ARGV[1], ARGV[2])
redis.call('HSET', KEYS[4], ARGV[1], ARGV[3])
return ticket
LUA;

    private const TRY_ACQUIRE_SCRIPT = <<<'LUA'
local expired = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[3])
for _, token in ipairs(expired) do
    redis.call('ZREM', KEYS[1], token)
    redis.call('HDEL', KEYS[2], token)
end

local tokens = redis.call('ZRANGE', KEYS[3], 0, -1)
for _, token in ipairs(tokens) do
    local since = tonumber(redis.call('HGET', KEYS[5], token) or '0')
    if since == 0 or ARGV[3] - since > tonumber(ARGV[6]) then
        redis.call('ZREM', KEYS[3], token)
        redis.call('HDEL', KEYS[4], token)
        redis.call('HDEL', KEYS[5], token)
    end
end

if redis.call('ZSCORE', KEYS[3], ARGV[1]) == false then
    return -1
end

if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[4]) then
    return 0
end

local active_orgs = {}
local active_tokens = redis.call('ZRANGE', KEYS[1], 0, -1)
for _, token in ipairs(active_tokens) do
    local organization = redis.call('HGET', KEYS[2], token) or ''
    active_orgs[organization] = (active_orgs[organization] or 0) + 1
end

local seen_orgs = {}
for _, token in ipairs(redis.call('ZRANGE', KEYS[3], 0, -1)) do
    local organization = redis.call('HGET', KEYS[4], token) or ''
    if organization ~= '' and not seen_orgs[organization] then
        seen_orgs[organization] = true
        if (active_orgs[organization] or 0) < tonumber(ARGV[7]) then
            if token == ARGV[1] and organization == ARGV[2] then
                redis.call('ZADD', KEYS[1], ARGV[3] + tonumber(ARGV[5]), token)
                redis.call('HSET', KEYS[2], token, organization)
                redis.call('ZREM', KEYS[3], token)
                redis.call('HDEL', KEYS[4], token)
                redis.call('HDEL', KEYS[5], token)
                return 1
            end
        end
    end
end

return 0
LUA;

    private const RELEASE_SCRIPT = <<<'LUA'
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('HDEL', KEYS[4], ARGV[1])
redis.call('HDEL', KEYS[5], ARGV[1])
return 1
LUA;

    private const RENEW_SCRIPT = <<<'LUA'
if redis.call('ZSCORE', KEYS[1], ARGV[1]) == false then
    return 0
end
redis.call('ZADD', KEYS[1], ARGV[2] + tonumber(ARGV[3]), ARGV[1])
return 1
LUA;

    public function __construct(private readonly string $keyPrefix = 'most:ai-assistant:read-concurrency:v1:')
    {
        if (trim($keyPrefix) === '') {
            throw new RuntimeException('assistant_read_limiter_key_prefix_invalid');
        }
    }

    /**
     * @template TResult
     * @param callable(callable(): void): TResult $read
     * @param callable(): mixed $checkpoint
     * @return TResult
     */
    public function run(callable $read, callable $checkpoint, int|string $organizationId): mixed
    {
        $organization = trim((string) $organizationId);
        if ($organization === '') {
            throw new RuntimeException('assistant_read_limiter_organization_invalid');
        }

        $token = (string) Str::uuid();
        $keys = $this->keys();
        $redisConnection = config('queue.connections.redis_ai_rag.connection', 'default');
        if (! is_string($redisConnection) || trim($redisConnection) === '') {
            $redisConnection = 'default';
        }
        $redis = Redis::connection($redisConnection);
        $startedAt = hrtime(true);
        try {
            $checkpoint();
            $nowMs = (int) floor(microtime(true) * 1000);
            $redis->eval(self::ENQUEUE_SCRIPT, 6, $keys['sequence'], $keys['waiters'], $keys['waiter_orgs'], $keys['waiter_since'], $keys['active'], $keys['active_orgs'], $token, $organization, $nowMs, self::STALE_WAITER_TTL_MS);

            do {
                $checkpoint();
                $nowMs = (int) floor(microtime(true) * 1000);
                $result = (int) $redis->eval(
                    self::TRY_ACQUIRE_SCRIPT,
                    5,
                    $keys['active'],
                    $keys['active_orgs'],
                    $keys['waiters'],
                    $keys['waiter_orgs'],
                    $keys['waiter_since'],
                    $token,
                    $organization,
                    $nowMs,
                    self::CAPACITY,
                    self::LEASE_TTL_MS,
                    self::STALE_WAITER_TTL_MS,
                    self::PER_ORGANIZATION_CAPACITY,
                );

                if ($result === 1) {
                    $readStartedAt = hrtime(true);
                    $readCheckpoint = function () use ($checkpoint, $redis, $keys, $token, $readStartedAt): void {
                        $checkpoint();
                        if (((hrtime(true) - $readStartedAt) / 1_000_000) >= self::READ_PHASE_DEADLINE_MS) {
                            throw new AssistantReadPermitTimeoutException('assistant_read_phase_deadline_exceeded');
                        }

                        $renewed = (int) $redis->eval(
                            self::RENEW_SCRIPT,
                            1,
                            $keys['active'],
                            $token,
                            (int) floor(microtime(true) * 1000),
                            self::LEASE_TTL_MS,
                        );
                        if ($renewed !== 1) {
                            throw new AssistantReadPermitTimeoutException('assistant_read_permit_lease_lost');
                        }
                    };
                    $readCheckpoint();
                    $value = $read($readCheckpoint);
                    $readCheckpoint();

                    return $value;
                }

                if ($result < 0) {
                    throw new AssistantReadPermitTimeoutException('assistant_read_permit_expired');
                }

                if (((hrtime(true) - $startedAt) / 1_000_000) >= self::ADMISSION_TIMEOUT_MS) {
                    throw new AssistantReadPermitTimeoutException('assistant_read_permit_timeout');
                }

                usleep(self::POLL_INTERVAL_US);
            } while (true);
        } finally {
            $redis->eval(self::RELEASE_SCRIPT, 5, $keys['active'], $keys['active_orgs'], $keys['waiters'], $keys['waiter_orgs'], $keys['waiter_since'], $token);
        }
    }

    /** @return array{sequence: string, active: string, active_orgs: string, waiters: string, waiter_orgs: string, waiter_since: string} */
    private function keys(): array
    {
        $prefix = $this->keyPrefix.'{assistant-read-concurrency}:';

        return [
            'sequence' => $prefix.'sequence',
            'active' => $prefix.'active',
            'active_orgs' => $prefix.'active-orgs',
            'waiters' => $prefix.'waiters',
            'waiter_orgs' => $prefix.'waiter-orgs',
            'waiter_since' => $prefix.'waiter-since',
        ];
    }
}
