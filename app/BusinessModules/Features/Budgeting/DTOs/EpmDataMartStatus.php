<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final class EpmDataMartStatus
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const STALE = 'stale';
    public const PARTIAL = 'partial';
    public const ONLINE = 'online';
    public const UNAVAILABLE = 'unavailable';

    public static function normalize(mixed $status): string
    {
        $value = is_string($status) ? mb_strtolower(trim($status)) : '';

        return in_array($value, self::all(), true) ? $value : self::ONLINE;
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, [self::QUEUED, self::RUNNING], true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::SUCCEEDED, self::FAILED, self::STALE, self::PARTIAL], true);
    }

    public static function all(): array
    {
        return [
            self::QUEUED,
            self::RUNNING,
            self::SUCCEEDED,
            self::FAILED,
            self::STALE,
            self::PARTIAL,
            self::ONLINE,
            self::UNAVAILABLE,
        ];
    }
}
