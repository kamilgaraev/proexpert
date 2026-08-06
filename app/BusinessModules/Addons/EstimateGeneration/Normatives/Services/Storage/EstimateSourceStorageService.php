<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage;

use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use InvalidArgumentException;

class EstimateSourceStorageService
{
    private const ROOT_PREFIX = 'estimate-sources/';

    public function __construct(private readonly FileService $files) {}

    /** @return array<int, string> */
    public function listFiles(int $organizationId, string $prefix): array
    {
        $files = $this->files->listCurrent($this->scopePrefix($organizationId, $prefix));

        sort($files);

        return array_values($files);
    }

    /** @return resource */
    public function openReadStream(int $organizationId, string $key)
    {
        $normalizedKey = $this->normalizePath($key);
        $this->guardOrganization($organizationId);
        $this->guardScopedEstimateSourcePath($organizationId, $normalizedKey);

        return $this->files->readCurrent($normalizedKey);
    }

    public function scopePrefix(int $organizationId, string $prefix): string
    {
        $this->guardOrganization($organizationId);
        $normalizedPrefix = $this->normalizePath($prefix);
        $this->guardRelativeEstimateSourcePath($normalizedPrefix);

        return OrganizationStoragePath::forOrganization($organizationId, $normalizedPrefix);
    }

    private function guardOrganization(int $organizationId): void
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('organization_storage_path_invalid');
        }
    }

    private function guardRelativeEstimateSourcePath(string $path): void
    {
        if (! str_starts_with($path, self::ROOT_PREFIX) || str_starts_with($path, 'org-')) {
            throw new InvalidArgumentException('estimate_source_path_invalid');
        }
    }

    private function guardScopedEstimateSourcePath(int $organizationId, string $path): void
    {
        if (! str_starts_with($path, "org-{$organizationId}/".self::ROOT_PREFIX)) {
            throw new InvalidArgumentException('estimate_source_path_invalid');
        }
    }

    private function normalizePath(string $path): string
    {
        $normalized = ltrim(trim(str_replace('\\', '/', $path)), '/');

        if (
            $normalized === ''
            || strlen($normalized) > 1024
            || str_contains($normalized, '://')
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1
        ) {
            throw new InvalidArgumentException('estimate_source_path_invalid');
        }

        return $normalized;
    }
}
