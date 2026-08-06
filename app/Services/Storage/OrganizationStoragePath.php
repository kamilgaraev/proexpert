<?php

declare(strict_types=1);

namespace App\Services\Storage;

final class OrganizationStoragePath
{
    private const DOMAINS = [
        'acts',
        'design-models',
        'estimates',
        'legal-archive',
        'procurement',
        'quality-control',
        'reports',
        'temporary',
    ];

    public static function forDomain(
        int $organizationId,
        string $domain,
        string $scope,
        string $objectId,
        string $extension,
    ): string {
        if (
            $organizationId < 1
            || ! in_array($domain, self::DOMAINS, true)
            || ! self::isValidScope($scope)
            || ! self::isValidSegment($objectId)
            || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1
        ) {
            throw new \InvalidArgumentException('organization_storage_path_invalid');
        }

        return self::build($organizationId, $domain, $scope, $objectId, $extension);
    }

    public static function personal(
        int $organizationId,
        int $userId,
        string $objectId,
        string $extension,
    ): string {
        if (
            $organizationId < 1
            || $userId < 1
            || ! self::isValidSegment($objectId)
            || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1
        ) {
            throw new \InvalidArgumentException('organization_storage_path_invalid');
        }

        return self::build(
            $organizationId,
            'personal-files',
            "user-{$userId}",
            $objectId,
            $extension,
        );
    }

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
        return 'reports/'.(string) $organizationId;
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
        return 'org-'.(string) $organizationId;
    }

    private static function normalizeSeparators(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private static function isValidScope(string $scope): bool
    {
        if ($scope === '' || strlen($scope) > 512 || str_contains($scope, '\\')) {
            return false;
        }

        $segments = explode('/', $scope);
        if (count($segments) > 16) {
            return false;
        }

        foreach ($segments as $segment) {
            if (! self::isValidSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidSegment(string $segment): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $segment) === 1;
    }

    private static function build(
        int $organizationId,
        string $domain,
        string $scope,
        string $objectId,
        string $extension,
    ): string {
        return "org-{$organizationId}/{$domain}/{$scope}/{$objectId}.{$extension}";
    }
}
