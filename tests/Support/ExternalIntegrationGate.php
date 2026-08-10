<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class ExternalIntegrationGate
{
    public static function enabled(string $name): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return false;
        }
        if ($value !== '1') {
            throw new RuntimeException($name.' must be exactly 1 when configured.');
        }

        return true;
    }

    public static function required(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException($name.' is required for the enabled integration gate.');
        }

        return trim($value);
    }
}
