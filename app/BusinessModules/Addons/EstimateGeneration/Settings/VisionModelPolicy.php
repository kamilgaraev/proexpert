<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use DomainException;

final class VisionModelPolicy
{
    public const LUNA = 'openai/gpt-6-luna';

    public static function assertSupported(string $model): string
    {
        if ($model !== self::LUNA) {
            throw new DomainException('estimate_generation_vision_replan_required');
        }

        return $model;
    }

    public static function isLuna(string $model): bool
    {
        return self::assertSupported($model) === self::LUNA;
    }
}
