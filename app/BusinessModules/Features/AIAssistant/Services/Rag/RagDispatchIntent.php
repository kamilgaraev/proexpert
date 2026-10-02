<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class RagDispatchIntent
{
    private const PREFIX = 'dispatch_pending:';

    public static function pending(?Throwable $exception = null): string
    {
        $marker = self::PREFIX.bin2hex(random_bytes(16));
        return $exception === null ? $marker : self::failed($marker, $exception);
    }

    public static function failed(string $marker, Throwable $exception): string
    {
        $attempt = substr($marker, 0, strlen(self::PREFIX) + 32);
        $class = $exception::class;
        if (str_contains($class, '@anonymous') || str_contains($class, "\0")) {
            $class = get_parent_class($exception) ?: Throwable::class;
        }
        return $attempt.':'.mb_strcut($class, 0, 255 - strlen($attempt) - 1, 'UTF-8');
    }

    public static function isPending(?string $error): bool
    {
        return $error !== null && preg_match('/^dispatch_pending:[a-f0-9]{32}(?::[^\x00]*)?$/D', $error) === 1;
    }

    public static function publicError(?string $error): ?string
    {
        if (! self::isPending($error)) {
            return $error;
        }
        $class = substr((string) $error, strlen(self::PREFIX) + 33);
        return $class === '' ? null : $class;
    }

    public static function scopePending(Builder $query): void
    {
        $query->where('last_error', 'like', 'dispatch\\_pending:%');
    }
}
