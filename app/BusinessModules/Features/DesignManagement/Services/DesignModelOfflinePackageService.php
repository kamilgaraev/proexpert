<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use DomainException;

final readonly class DesignModelOfflinePackageService
{
    public function __construct(
        private DesignModelSessionAccessService $access,
        private FileService $files,
        private DesignBimLocalizationService $localization,
    ) {
    }

    public function offlinePackage(User $actor, int $organizationId, DesignArtifactVersion $version): array
    {
        $version = DesignArtifactVersion::query()
            ->whereKey($version->id)
            ->where('organization_id', $organizationId)
            ->where('file_format', 'ifc')
            ->whereHas('project', fn ($projects) => $projects->where('organization_id', $organizationId))
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $organizationId)
                ->where('project_id', $version->project_id)
                ->whereHas('package', fn ($packages) => $packages->where('organization_id', $organizationId)
                    ->where('project_id', $version->project_id)))
            ->first();

        if (! $version instanceof DesignArtifactVersion
            || ! $this->access->canAccessProject($actor, $organizationId, (int) $version->project_id)) {
            throw new DomainException(trans_message('design_bim.errors.forbidden'));
        }

        $derivative = DesignModelDerivative::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $version->project_id)
            ->where('version_id', $version->id)
            ->where('viewer_provider', 'thatopen')
            ->where('derivative_format', 'thatopen_frag')
            ->where('status', DesignDerivativeStatusEnum::READY->value)
            ->first();

        if (! $derivative instanceof DesignModelDerivative || ! DesignViewerConverter::isCurrent($derivative)) {
            $this->unavailable();
        }

        $metadata = $derivative->metadata ?? [];
        $package = $metadata['offline_package'] ?? [];
        $generation = $package['generation'] ?? null;
        if (($package['schema_version'] ?? null) !== 1 || ! is_string($generation)
            || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $generation)
            || ($metadata['generation'] ?? null) !== $generation
            || ! is_string($metadata['runtime']['fragments'] ?? null)
            || $metadata['runtime']['fragments'] === '') {
            $this->unavailable();
        }

        $organization = Organization::query()->find($organizationId);
        if (! $organization instanceof Organization) {
            $this->unavailable();
        }

        $prefix = sprintf(
            'org-%d/pir/projects/%d/packages/%d/models/%d/viewer/%s/',
            $organizationId,
            $version->project_id,
            $version->artifact->package_id,
            $version->id,
            $generation,
        );
        $geometry = $this->filePayload($package['geometry'] ?? [], $prefix.'model.frag', $organization);
        $properties = $this->filePayload($package['properties'] ?? [], $prefix.'properties.ndjson', $organization);
        if ($derivative->derivative_file_path !== $prefix.'model.frag') {
            $this->unavailable();
        }

        return [
            'schema_version' => 1,
            'generation' => $generation,
            'version_id' => (int) $version->id,
            'derivative_id' => (int) $derivative->id,
            'converter_version' => (int) $metadata['converter_version'],
            'runtime' => $metadata['runtime'],
            'geometry' => $geometry,
            'properties' => $properties,
            'localization' => $this->localization->dictionary(),
            'expires_at' => now()->addMinutes(60)->toIso8601String(),
        ];
    }

    private function filePayload(mixed $file, string $expectedPath, Organization $organization): array
    {
        if (! is_array($file) || ($file['path'] ?? null) !== $expectedPath
            || ! is_string($file['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $file['sha256'])
            || ! is_int($file['size'] ?? null) || $file['size'] <= 0
            || ! is_string($file['mime'] ?? null)) {
            $this->unavailable();
        }

        $disk = $this->files->disk($organization);
        if (! $disk->exists($expectedPath) || $disk->size($expectedPath) !== $file['size']) {
            $this->unavailable();
        }
        $url = $this->files->temporaryUrl($expectedPath, 60, $organization);
        if ($url === null) {
            $this->unavailable();
        }

        return ['url' => $url, 'sha256' => $file['sha256'], 'size' => $file['size'], 'mime' => $file['mime']];
    }

    private function unavailable(): never
    {
        throw new DomainException(trans_message('design_management.errors.derivative_file_not_available'));
    }
}
