<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use Illuminate\Support\Str;

final class DesignStoragePathService
{
    public function sourcePath(
        int $organizationId,
        int $projectId,
        int $packageId,
        int $versionId,
        string $originalName
    ): string {
        return $this->basePath($organizationId, $projectId, $packageId, $versionId)
            . '/source/'
            . $this->safeFileName($originalName, 'model.ifc');
    }

    public function derivativePath(
        int $organizationId,
        int $projectId,
        int $packageId,
        int $versionId,
        string $extension
    ): string {
        $safeExtension = strtolower(trim($extension, ". \t\n\r\0\x0B"));
        $safeExtension = preg_replace('/[^a-z0-9]+/', '', $safeExtension) ?: 'frag';

        return $this->basePath($organizationId, $projectId, $packageId, $versionId)
            . '/viewer/model.'
            . $safeExtension;
    }

    public function documentSourcePath(
        int $organizationId,
        int $projectId,
        int $packageId,
        int $versionId,
        string $originalName
    ): string {
        return sprintf(
            'org-%d/pir/projects/%d/packages/%d/documents/%d/source/%s',
            $organizationId,
            $projectId,
            $packageId,
            $versionId,
            $this->safeFileName($originalName, 'document.pdf')
        );
    }

    public function multipartSourcePath(
        int $organizationId,
        int $projectId,
        int $packageId,
        string $uploadId,
        string $originalName
    ): string {
        return sprintf(
            'org-%d/pir/projects/%d/packages/%d/model-uploads/%s/source/%s',
            $organizationId,
            $projectId,
            $packageId,
            $this->safeUploadId($uploadId),
            $this->safeFileName($originalName, 'model.ifc')
        );
    }

    private function basePath(int $organizationId, int $projectId, int $packageId, int $versionId): string
    {
        return sprintf(
            'org-%d/pir/projects/%d/packages/%d/models/%d',
            $organizationId,
            $projectId,
            $packageId,
            $versionId
        );
    }

    private function safeFileName(string $name, string $defaultName): string
    {
        $baseName = basename(str_replace('\\', '/', $name));
        $extension = strtolower((string) pathinfo($baseName, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($baseName, PATHINFO_FILENAME);
        $safeStem = Str::slug(Str::ascii($stem), '-');

        if ($safeStem === '') {
            $safeStem = (string) pathinfo($defaultName, PATHINFO_FILENAME);
        }

        $safeExtension = preg_replace('/[^a-z0-9]+/', '', $extension);
        if (!$safeExtension) {
            $safeExtension = (string) pathinfo($defaultName, PATHINFO_EXTENSION);
        }

        return $safeStem . '.' . $safeExtension;
    }

    private function safeUploadId(string $uploadId): string
    {
        $safeUploadId = preg_replace('/[^a-zA-Z0-9-]+/', '', $uploadId);

        return $safeUploadId !== '' ? $safeUploadId : (string) Str::uuid();
    }
}
