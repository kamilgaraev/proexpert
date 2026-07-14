<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class ReviewSummarySnapshot
{
    public const VERSION = 2;

    /** @param array<string, mixed> $draft @param array<string, int> $summary @return array<string, int|string> */
    public static function create(array $draft, array $summary): array
    {
        return [
            ...$summary,
            'classifier_version' => self::VERSION,
            'source_version' => self::contentVersion($draft),
            'input_version' => self::inputVersion($draft) ?? '',
        ];
    }

    /** @param array<string, mixed> $draft @param array<string, mixed> $snapshot */
    public static function isFresh(array $draft, array $snapshot): bool
    {
        $contentVersion = self::contentVersion($draft);
        $declaredContentVersion = data_get($draft, 'quality_summary.content_version');
        $inputVersion = self::inputVersion($draft);

        return (int) ($snapshot['classifier_version'] ?? 0) === self::VERSION
            && is_string($declaredContentVersion)
            && preg_match('/^sha256:[a-f0-9]{64}$/', $declaredContentVersion) === 1
            && hash_equals($contentVersion, $declaredContentVersion)
            && is_string($snapshot['source_version'] ?? null)
            && hash_equals($contentVersion, $snapshot['source_version'])
            && $inputVersion !== null
            && is_string($snapshot['input_version'] ?? null)
            && hash_equals($inputVersion, $snapshot['input_version']);
    }

    /** @param array<string, mixed> $draft */
    public static function contentVersion(array $draft): string
    {
        return 'sha256:'.hash('sha256', json_encode($draft['local_estimates'] ?? [], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $draft */
    private static function inputVersion(array $draft): ?string
    {
        $inputVersion = $draft['source_input_version'] ?? null;

        return is_string($inputVersion) && preg_match('/^sha256:[a-f0-9]{64}$/', $inputVersion) === 1
            ? $inputVersion
            : null;
    }
}
