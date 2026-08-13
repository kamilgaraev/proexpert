<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use DomainException;

final class VisionModelPolicy
{
    public const LUNA = 'openai/gpt-5.6-luna';

    private const LEGACY_REPLAY_MODELS = [
        'gemini/gemini-3.1-flash',
        'gemini/gemini-3.5-flash',
    ];

    public static function assertSupported(string $model): string
    {
        if ($model !== self::LUNA && ! in_array($model, self::LEGACY_REPLAY_MODELS, true)) {
            throw new DomainException('estimate_generation_vision_model_unsupported');
        }

        return $model;
    }

    public static function isLuna(string $model): bool
    {
        return self::assertSupported($model) === self::LUNA;
    }
}
