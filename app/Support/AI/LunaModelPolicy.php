<?php

declare(strict_types=1);

namespace App\Support\AI;

use DomainException;

final class LunaModelPolicy
{
    public const OPENAI = 'gpt-6-luna';

    public const TIMEWEB = 'openai/gpt-6-luna';

    public static function forProvider(string $provider): string
    {
        return match ($provider) {
            'timeweb' => self::TIMEWEB,
            'openai' => self::OPENAI,
            default => throw new DomainException('ai_luna_provider_required'),
        };
    }

    public static function assert(string $model, string $provider = 'openai'): string
    {
        $expected = self::forProvider($provider);
        $normalized = strtolower(trim($model));

        if ($normalized !== $expected) {
            throw new DomainException('ai_luna_model_required');
        }

        return $expected;
    }

    public static function isLuna(string $model, string $provider = 'openai'): bool
    {
        try {
            self::assert($model, $provider);

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
