<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class ReviewSummarySnapshot
{
    public const VERSION = 1;

    /** @param array<string, mixed> $draft @param array<string, int> $summary @return array<string, int|string> */
    public static function create(array $draft, array $summary): array
    {
        return [
            ...$summary,
            'classifier_version' => self::VERSION,
            'source_version' => self::contentVersion($draft),
        ];
    }

    /** @param array<string, mixed> $draft @param array<string, mixed> $snapshot */
    public static function isFresh(array $draft, array $snapshot): bool
    {
        return (int) ($snapshot['classifier_version'] ?? 0) === self::VERSION
            && is_string($snapshot['source_version'] ?? null)
            && hash_equals(self::contentVersion($draft), $snapshot['source_version']);
    }

    /** @param array<string, mixed> $draft */
    public static function contentVersion(array $draft): string
    {
        return 'sha256:'.hash('sha256', json_encode($draft['local_estimates'] ?? [], JSON_THROW_ON_ERROR));
    }
}
