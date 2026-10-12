<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\AIAssistant\Services\Rag\DesignRagMutationBridge;

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
use App\Models\User;
use Illuminate\Validation\ValidationException;
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

        app(DesignAccessService::class)->assertPackage($package, User::query()->findOrFail($userId), 'design-management.documents.upload');
        $metadata = $this->metadataExtractor->inspect($file);
        $fileFormat = (string) $metadata['file_format'];
        $this->assertFormatAllowed($section, (string) $payload['document_code'], $fileFormat);

        return DB::transaction(function () use ($section, $package, $userId, $file, $payload, $metadata, $fileFormat): DesignArtifactVersion {
            $package = $this->lockPackage($package);
            $section = DesignPackageSection::query()->whereKey($section->id)->where('package_id', $package->id)->where('organization_id', $package->organization_id)->where('project_id', $package->project_id)->lockForUpdate()->firstOrFail();
            if (($section->metadata['composition_active'] ?? true) === false) {
                throw new DomainException(trans_message('design_management.errors.section_not_found'));
            }
            $this->assertFormatAllowed($section, (string) $payload['document_code'], $fileFormat);
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

            $this->invalidate($package, $userId);
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

        $this->assertDocumentVersion($version);
        app(DesignAccessService::class)->assertVersion($version, User::query()->findOrFail($userId), 'design-management.documents.edit');
        $this->validateSheets($sheets);

        DB::transaction(function () use ($version, $artifact, $package, $sheets, $userId): void {
            $package = $this->lockPackage($package);
            $version = DesignArtifactVersion::query()->whereKey($version->id)->where('artifact_id', $artifact->id)->lockForUpdate()->firstOrFail();
            if ($version->is_current && in_array($version->status->value, ['archived', 'superseded'], true)) {
                throw new DomainException(trans_message('design_management.errors.version_not_found'));
            }
            if (\App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent::query()->where('package_id', $package->id)->where('organization_id', $package->organization_id)->where('action', 'issue')->whereJsonContains('metadata->artifact_version_ids', (int) $version->id)->exists()) {
                throw new DomainException(trans_message('design_management.errors.package_locked_for_document_changes'));
            }
            $this->invalidate($package, $userId);
            app(DesignRagMutationBridge::class)->changedRows(DesignDocumentSheet::class, DesignDocumentSheet::query()->where('version_id', $version->id));
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
            app(DesignRagMutationBridge::class)->changedRows(DesignDocumentSheet::class, DesignDocumentSheet::query()->where('version_id', $version->id));
        });

        return $version->fresh(['artifact.section', 'sheets']);
    }

    public function findVersion(int $organizationId, int $versionId, ?User $actor = null, string $permission = 'design-management.documents.view'): ?DesignArtifactVersion
    {
        $access = app(DesignAccessService::class);
        $actor = $access->requireActor($actor);
        $version = DesignArtifactVersion::forOrganization($organizationId)
            ->with(['artifact.section', 'artifact.package.project:id,name,organization_id', 'sheets'])
            ->find($versionId);
        if ($version !== null) {
            try {
                $access->assertVersion($version, $actor, $permission);
            } catch (DomainException) {
                return null;
            }
            $this->assertDocumentVersion($version);
        }
        return $version;
    }

    public function sourceFileStream(DesignArtifactVersion $version, ?User $actor = null): array
    {
        $this->assertDocumentVersion($version);
        app(DesignAccessService::class)->assertVersion($version, app(DesignAccessService::class)->requireActor($actor), 'design-management.documents.view');
        if (! str_starts_with((string) $version->source_file_path, 'org-'.$version->organization_id.'/')) {
            throw new DomainException(trans_message('design_management.errors.document_file_not_available'));
        }
        return $this->fileStream(
            (int) $version->organization_id,
            (string) $version->source_file_path,
            $version->source_original_name ?: 'document.' . ($version->file_format ?: 'pdf'),
            $version->source_mime_type ?: 'application/octet-stream',
            trans_message('design_management.errors.document_file_not_available')
        );
    }

    private function assertDocumentVersion(DesignArtifactVersion $version): void
    {
        $version->loadMissing('artifact.section');
        $artifact = $version->artifact;
        if ($artifact === null || ($artifact->artifact_type === DesignArtifactTypeEnum::MODEL
            && ($artifact->section_id === null || $artifact->section === null || trim((string) $artifact->document_code) === ''))) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }
    }

    private function lockPackage(DesignPackage $package): DesignPackage
    {
        $locked = DesignPackage::query()->whereKey($package->id)->where('organization_id', $package->organization_id)
            ->where('project_id', $package->project_id)->lockForUpdate()->firstOrFail();
        $this->assertPackageAcceptsDocumentChanges($locked);
        return $locked;
    }

    private function invalidate(DesignPackage $package, int $userId): void
    {
        app(DesignCompletenessService::class)->invalidateLockedInputs($package, $userId);
        $package->refresh();
        foreach ($package->sections()->get() as $section) {
            $sectionMetadata = $section->metadata ?? [];
            $sectionMetadata['readiness_status'] = 'not_checked';
            $sectionMetadata['readiness_results'] = [];
            $section->update(['metadata' => $sectionMetadata]);
        }
    }

    private function validateSheets(array $sheets): void
    {
        $seen = [];
        if ($sheets === [] || count($sheets) > 1000) {
            throw ValidationException::withMessages(['sheets' => trans_message('design_management.errors.validation_failed')]);
        }
        foreach ($sheets as $index => $sheet) {
            $number = is_array($sheet) ? trim((string) ($sheet['sheet_number'] ?? '')) : '';
            $title = is_array($sheet) ? trim((string) ($sheet['sheet_title'] ?? '')) : '';
            if ($number === '' || $title === '' || mb_strlen($number) > 80 || mb_strlen($title) > 255 || isset($seen[$number])) {
                throw ValidationException::withMessages(['sheets.'.$index => trans_message('design_management.errors.validation_failed')]);
            }
            $seen[$number] = true;
            foreach (['file_page_number', 'total_sheets'] as $field) {
                if (isset($sheet[$field]) && (filter_var($sheet[$field], FILTER_VALIDATE_INT) === false || (int) $sheet[$field] < 1)) {
                    throw ValidationException::withMessages(['sheets.'.$index.'.'.$field => trans_message('design_management.errors.validation_failed')]);
                }
            }
        }
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
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
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
        $section->loadMissing('package');
        if ($section->package?->composition_revision_id !== null) {
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
