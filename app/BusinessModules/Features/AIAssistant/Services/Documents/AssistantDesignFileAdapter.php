<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantDesignFileAdapter
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy) {}

    public function map(User $actor, int $organizationId, string|int $versionId, string $path): File
    {
        return DB::transaction(function () use ($actor, $organizationId, $versionId, $path): File {
            $query = $this->policy->entityQuery($actor, $organizationId, 'design_artifact_version');
            $version = $query?->whereKey($versionId)->lockForUpdate()->first();
            if (! $version instanceof DesignArtifactVersion || ! $this->policy->canReadEntityContent($actor, $organizationId, 'design_artifact_version', $versionId)) {
                throw new RuntimeException('ai_assistant_document_access_denied');
            }
            $this->assertSource($version, $path);
            $fingerprint = $this->fingerprint($version);
            $mapping = ['organization_id' => $organizationId, 'fileable_type' => $version->getMorphClass(),
                'fileable_id' => $version->id, 'path' => $path, 'disk' => 's3'];
            $file = File::query()->where($mapping)->where('additional_info->design_source_version', $fingerprint)->first();
            if ($file === null) {
                $file = File::withoutEvents(fn (): File => File::query()->create($mapping + ['user_id' => $actor->id,
                    'name' => $version->source_original_name, 'original_name' => $version->source_original_name,
                    'mime_type' => $version->source_mime_type, 'size' => $version->source_size_bytes,
                    'type' => 'document', 'category' => 'ai_assistant', 'additional_info' => ['assistant_native_source' => 'design',
                        'design_source_version' => $fingerprint, 'design_source_sha256' => $version->source_sha256]]));
            }
            foreach (File::query()->where($mapping)->where('additional_info->assistant_native_source', 'design')->whereKeyNot($file->id)->get() as $obsolete) {
                $obsolete->deleteQuietly();
            }
            $this->assertMapping($file);

            return $file;
        });
    }

    public function assertMapping(File $file): void
    {
        $version = DesignArtifactVersion::query()->where('organization_id', $file->organization_id)->find($file->fileable_id);
        if ($version === null || $file->disk !== 's3' || $this->policy->entityTypeForModel((string) $file->fileable_type) !== 'design_artifact_version') {
            throw new RuntimeException('ai_assistant_document_parent_invalid');
        }
        $this->assertSource($version, (string) $file->path);
        if (($file->additional_info['assistant_native_source'] ?? null) !== 'design'
            || ($file->additional_info['design_source_version'] ?? null) !== $this->fingerprint($version)
            || (string) ($file->additional_info['design_source_sha256'] ?? '') !== (string) $version->source_sha256) {
            throw new RuntimeException('ai_assistant_document_source_changed');
        }
    }

    private function assertSource(DesignArtifactVersion $version, string $path): void
    {
        $artifact = $version->artifact;
        $package = $artifact?->package;
        $project = $version->project;
        if ($artifact === null || $package === null || $project === null
            || (int) $artifact->organization_id !== (int) $version->organization_id || (int) $package->organization_id !== (int) $version->organization_id
            || (int) $project->organization_id !== (int) $version->organization_id || (int) $artifact->project_id !== (int) $version->project_id
            || (int) $package->project_id !== (int) $version->project_id || $path !== $version->source_file_path) {
            throw new RuntimeException('ai_assistant_document_parent_invalid');
        }
        $prefix = 'org-'.$version->organization_id.'/pir/projects/'.$version->project_id.'/packages/'.$package->id.'/';
        $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
        $expected = '(?:models|documents)/'.preg_quote((string) $version->id, '#').'/source/[A-Za-z0-9_-]+\.[A-Za-z0-9]+';
        if ((int) $version->uploaded_by > 0) {
            $expected = '(?:'.$expected.'|model-uploads/user-'.(int) $version->uploaded_by.'/[A-Za-z0-9-]+/source/[A-Za-z0-9_-]+\.[A-Za-z0-9]+)';
        }
        if (! preg_match('#^'.$expected.'$#D', $relative)) {
            throw new RuntimeException('ai_assistant_document_storage_unsupported');
        }
    }

    private function fingerprint(DesignArtifactVersion $version): string
    {
        return hash('sha256', json_encode(array_intersect_key($version->getAttributes(), array_flip([
            'source_file_path', 'source_original_name', 'source_mime_type', 'source_size_bytes', 'source_sha256',
        ])), JSON_THROW_ON_ERROR));
    }
}
