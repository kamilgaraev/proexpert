<?php

declare(strict_types=1);

namespace App\Services\Storage;

final class OrganizationStoragePath
{
    public static function forOrganization(int|string $organizationId, string $path): string
    {
        $organizationPrefix = self::organizationPrefix($organizationId);
        $normalizedPath = self::normalizeSeparators($path);

        if ($normalizedPath === '' || $normalizedPath === $organizationPrefix) {
            return $organizationPrefix;
        }

        if (str_starts_with($normalizedPath, "{$organizationPrefix}/")) {
            return $normalizedPath;
        }

        if (preg_match('/^org-\d+\/(.+)$/', $normalizedPath, $matches) === 1) {
            return "{$organizationPrefix}/{$matches[1]}";
        }

        return "{$organizationPrefix}/{$normalizedPath}";
    }

    public static function normalizeLegacyPath(int|string $organizationId, string $path): string
    {
        $normalizedPath = self::normalizeSeparators($path);
        $quotedOrganizationId = preg_quote((string) $organizationId, '/');

        if (preg_match("/^reports\/{$quotedOrganizationId}\/(.+)$/", $normalizedPath, $matches) === 1) {
            return self::forOrganization($organizationId, "reports/{$matches[1]}");
        }

        if (preg_match("/^estimate-imports\/org-{$quotedOrganizationId}\/(.+)$/", $normalizedPath, $matches) === 1) {
            return self::forOrganization($organizationId, "estimate-imports/{$matches[1]}");
        }

        return self::forOrganization($organizationId, $normalizedPath);
    }

    public static function reportsDirectory(int|string $organizationId): string
    {
        return self::forOrganization($organizationId, 'reports');
    }

    public static function legacyReportsDirectory(int|string $organizationId): string
    {
        return 'reports/' . (string) $organizationId;
    }

    public static function displayPath(int|string|null $organizationId, string $path): string
    {
        $normalizedPath = self::normalizeSeparators($path);

        if (preg_match('/^org-\d+\/(.+)$/', $normalizedPath, $matches) === 1) {
            return $matches[1];
        }

        if ($organizationId !== null) {
            $quotedOrganizationId = preg_quote((string) $organizationId, '/');

            if (preg_match("/^reports\/{$quotedOrganizationId}\/(.+)$/", $normalizedPath, $matches) === 1) {
                return "reports/{$matches[1]}";
            }

            if (preg_match("/^estimate-imports\/org-{$quotedOrganizationId}\/(.+)$/", $normalizedPath, $matches) === 1) {
                return "estimate-imports/{$matches[1]}";
            }
        }

        return $normalizedPath;
    }

    private static function organizationPrefix(int|string $organizationId): string
    {
        return 'org-' . (string) $organizationId;
    }

    private static function normalizeSeparators(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
