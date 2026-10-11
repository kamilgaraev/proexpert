<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

final class DesignIfcIndexUpgradeState
{
    public static function active(array $metadata): bool
    {
        return is_string($metadata['index_upgrade']['generation'] ?? null)
            && in_array($metadata['index_upgrade']['status'] ?? null, ['queued', 'processing', 'failed'], true);
    }

    public static function generation(array $metadata): ?string
    {
        $generation = self::active($metadata) ? $metadata['index_upgrade']['generation'] : ($metadata['generation'] ?? null);

        return is_string($generation) ? $generation : null;
    }

    public static function sourceMatches(array $metadata, int $versionId, string $path, ?string $sha256): bool
    {
        if (! isset($metadata['index_upgrade'])) {
            return true;
        }

        $snapshot = $metadata['index_upgrade'];

        return ($snapshot['source_version_id'] ?? null) === $versionId
            && ($snapshot['source_path'] ?? null) === $path
            && ($snapshot['source_sha256'] ?? null) === $sha256;
    }
}
