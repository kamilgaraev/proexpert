<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignVersionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use App\Models\Organization;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class DesignDocumentArtifactService
{
    public function __construct(
        private readonly DesignStoragePathService $pathService,
        private readonly FileService $fileService,
        private readonly DesignDocumentMetadataExtractor $metadataExtractor,
    ) {
    }

    public function uploadDocument(
        DesignPackageSection $section,
        int $userId,
        UploadedFile $file,
        array $payload
    ): DesignArtifactVersion {
        $section->loadMissing('package');
        $package = $section->package;

        if (!$package instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.section_not_found'));
        }

        $this->assertPackageAcceptsDocumentChanges($package);
        $metadata = $this->metadataExtractor->inspect($file);
        $fileFormat = (string) $metadata['file_format'];
        $this->assertFormatAllowed($section, (string) $payload['document_code'], $fileFormat);

        return DB::transaction(function () use ($section, $package, $userId, $file, $payload, $metadata, $fileFormat): DesignArtifactVersion {
            $artifact = $this->resolveArtifact($section, $userId, $payload);
            $version = $artifact->versions()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
                'created_by' => $userId,
                'updated_by' => $userId,
                'uploaded_by' => $userId,
                'title' => $payload['title'] ?? $artifact->title,
                'version_number' => (string) $payload['version_number'],
                'revision' => $payload['revision'] ?? null,
                'revision_label' => $payload['revision_label'] ?? ($payload['revision'] ?? null),
                'source_format' => $fileFormat,
                'file_format' => $fileFormat,
                'source_file_path' => 'pending',
                'source_original_name' => $file->getClientOriginalName(),
                'source_mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                'source_size_bytes' => (int) $file->getSize(),
                'source_sha256' => $metadata['sha256'] ?? null,
                'page_count' => $metadata['page_count'] ?? null,
                'sheet_count' => $metadata['sheet_count'] ?? null,
                'extracted_metadata' => $metadata,
                'status' => DesignVersionStatusEnum::UPLOADED,
                'is_current' => false,
                'metadata' => $payload['metadata'] ?? [],
            ]);

            $path = $this->pathService->documentSourcePath(
                (int) $package->organization_id,
                (int) $package->project_id,
                (int) $package->id,
                (int) $version->id,
                $file->getClientOriginalName()
            );

            $this->storeUploadedFile($file, $path, (int) $package->organization_id);
            $version->update(['source_file_path' => $path]);

            if ((bool) ($payload['make_current'] ?? true)) {
                $this->setCurrentVersion($version, $userId);
            }

            $section->update([
                'status' => DesignDocumentSectionStatusEnum::IN_WORK,
            ]);

            return $version->fresh([
                'artifact.section',
                'artifact.package.project:id,name,organization_id',
                'sheets',
            ]);
        });
    }

    public function replaceSheets(DesignArtifactVersion $version, int $userId, array $sheets): DesignArtifactVersion
    {
        $version->loadMissing('artifact.section', 'artifact.package');
        $artifact = $version->artifact;
        $package = $artifact?->package;

        if (!$artifact instanceof DesignArtifact || !$package instanceof DesignPackage) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        $this->assertPackageAcceptsDocumentChanges($package);

        DB::transaction(function () use ($version, $artifact, $package, $sheets, $userId): void {
            DesignDocumentSheet::query()
                ->where('version_id', $version->id)
                ->delete();

            foreach (array_values($sheets) as $index => $sheet) {
                DesignDocumentSheet::query()->create([
                    'organization_id' => $package->organization_id,
                    'project_id' => $package->project_id,
                    'package_id' => $package->id,
                    'section_id' => $artifact->section_id,
                    'artifact_id' => $artifact->id,
                    'version_id' => $version->id,
                    'sheet_number' => (string) ($sheet['sheet_number'] ?? ($index + 1)),
                    'sheet_code' => $sheet['sheet_code'] ?? null,
                    'sheet_title' => (string) ($sheet['sheet_title'] ?? ''),
                    'revision' => $sheet['revision'] ?? $version->revision_label ?? $version->revision,
                    'file_page_number' => isset($sheet['file_page_number']) ? (int) $sheet['file_page_number'] : $index + 1,
                    'total_sheets' => isset($sheet['total_sheets']) ? (int) $sheet['total_sheets'] : count($sheets),
                    'status' => $sheet['status'] ?? 'active',
                    'metadata' => $sheet['metadata'] ?? [],
                ]);
            }

            $version->update([
                'sheet_count' => count($sheets),
                'updated_by' => $userId,
            ]);
        });

        return $version->fresh(['artifact.section', 'sheets']);
    }

    public function findVersion(int $organizationId, int $versionId): ?DesignArtifactVersion
    {
        return DesignArtifactVersion::forOrganization($organizationId)
            ->with(['artifact.section', 'artifact.package.project:id,name,organization_id', 'sheets'])
            ->find($versionId);
    }

    public function sourceFileStream(DesignArtifactVersion $version): array
    {
        return $this->fileStream(
            (int) $version->organization_id,
            (string) $version->source_file_path,
            $version->source_original_name ?: 'document.' . ($version->file_format ?: 'pdf'),
            $version->source_mime_type ?: 'application/octet-stream',
            trans_message('design_management.errors.document_file_not_available')
        );
    }

    private function resolveArtifact(DesignPackageSection $section, int $userId, array $payload): DesignArtifact
    {
        if (!empty($payload['artifact_id'])) {
            $artifact = DesignArtifact::forOrganization((int) $section->organization_id)
                ->where('package_id', $section->package_id)
                ->where('section_id', $section->id)
                ->find((int) $payload['artifact_id']);

            if ($artifact instanceof DesignArtifact) {
                return tap($artifact)->update([
                    'updated_by' => $userId,
                    'document_code' => $payload['document_code'] ?? $artifact->document_code,
                    'document_title' => $payload['document_title'] ?? $artifact->document_title,
                    'requires_sheet_registry' => (bool) ($payload['requires_sheet_registry'] ?? $artifact->requires_sheet_registry),
                ]);
            }
        }

        $documentCode = (string) $payload['document_code'];
        $artifact = DesignArtifact::query()
            ->where('package_id', $section->package_id)
            ->where('section_id', $section->id)
            ->where('document_code', $documentCode)
            ->first();

        if ($artifact instanceof DesignArtifact) {
            $artifact->update([
                'updated_by' => $userId,
                'title' => $payload['title'] ?? $artifact->title,
                'document_title' => $payload['document_title'] ?? $artifact->document_title,
                'requires_sheet_registry' => (bool) ($payload['requires_sheet_registry'] ?? $artifact->requires_sheet_registry),
            ]);

            return $artifact;
        }

        return DesignArtifact::query()->create([
            'organization_id' => $section->organization_id,
            'project_id' => $section->project_id,
            'package_id' => $section->package_id,
            'section_id' => $section->id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'artifact_type' => $payload['artifact_type'] ?? DesignArtifactTypeEnum::TEXT_DOCUMENT,
            'document_code' => $documentCode,
            'document_title' => $payload['document_title'] ?? $payload['title'],
            'requires_sheet_registry' => (bool) ($payload['requires_sheet_registry'] ?? false),
            'title' => $payload['title'],
            'discipline' => $payload['discipline'] ?? $section->code,
            'stage' => $section->project_stage,
            'status' => 'active',
            'metadata' => $payload['artifact_metadata'] ?? [],
        ]);
    }

    private function assertFormatAllowed(DesignPackageSection $section, string $documentCode, string $fileFormat): void
    {
        $documents = $section->metadata['documents'] ?? [];

        foreach ($documents as $document) {
            if (($document['document_code'] ?? null) !== $documentCode) {
                continue;
            }

            $allowedFormats = $document['allowed_formats'] ?? [];

            if ($allowedFormats === [] || in_array($fileFormat, $allowedFormats, true)) {
                return;
            }

            throw new DomainException(trans_message('design_management.errors.document_format_not_allowed'));
        }
    }

    private function assertPackageAcceptsDocumentChanges(DesignPackage $package): void
    {
        if (!DesignPackageWorkflow::canChangeDocuments($package)) {
            throw new DomainException(trans_message('design_management.errors.package_locked_for_document_changes'));
        }
    }

    private function setCurrentVersion(DesignArtifactVersion $version, int $userId): void
    {
        DesignArtifactVersion::query()
            ->where('artifact_id', $version->artifact_id)
            ->whereKeyNot($version->id)
            ->update([
                'is_current' => false,
                'status' => DesignVersionStatusEnum::SUPERSEDED->value,
                'updated_by' => $userId,
            ]);

        $version->update([
            'is_current' => true,
            'status' => DesignVersionStatusEnum::CURRENT,
            'updated_by' => $userId,
        ]);
    }

    private function storeUploadedFile(UploadedFile $file, string $path, int $organizationId): void
    {
        $organization = Organization::query()->find($organizationId);
        $stream = $this->readUploadedFileStream($file);

        try {
            $stored = $this->fileService->disk($organization)->put($path, $stream, 'private');
        } finally {
            fclose($stream);
        }

        if (!$stored) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }
    }

    private function readUploadedFileStream(UploadedFile $file): mixed
    {
        $realPath = $file->getRealPath();

        if (!$realPath || !is_file($realPath)) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new DomainException(trans_message('design_management.errors.file_upload_failed'));
        }

        return $stream;
    }

    private function fileStream(int $organizationId, string $path, string $filename, string $mimeType, string $errorMessage): array
    {
        if ($path === '' || $path === 'pending') {
            throw new DomainException($errorMessage);
        }

        $organization = Organization::query()->find($organizationId);
        $stream = $this->fileService->disk($organization)->readStream($path);

        if (!is_resource($stream)) {
            throw new DomainException($errorMessage);
        }

        return [
            'stream' => $stream,
            'filename' => $filename,
            'mime_type' => $mimeType,
        ];
    }
}
