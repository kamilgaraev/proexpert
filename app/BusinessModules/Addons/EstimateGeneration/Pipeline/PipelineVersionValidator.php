<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final class PipelineVersionValidator
{
    public const MAX_LENGTH = 80;

    private const SAFE_FORMAT = '/\A[\p{L}\p{N}][\p{L}\p{N}:._-]*\z/u';

    public static function assertValid(string $version, string $name): void
    {
        if (
            $version === ''
            || ! mb_check_encoding($version, 'UTF-8')
            || mb_strlen($version, 'UTF-8') > self::MAX_LENGTH
            || preg_match(self::SAFE_FORMAT, $version) !== 1
        ) {
            throw new InvalidArgumentException(
                "Pipeline {$name} version must contain at most 80 letters, numbers, colons, dots, underscores or dashes.",
            );
        }
    }

    public static function assertSha256(string $version, string $name): void
    {
        if (preg_match('/\Asha256:[0-9a-f]{64}\z/', $version) !== 1) {
            throw new InvalidArgumentException("Pipeline {$name} version must be canonical sha256.");
        }
    }
}
