<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\Models\File;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use RuntimeException;

final class AssistantDocumentFileResolver
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy) {}

    public function parentType(File $file): string
    {
        if (app(AssistantOperationsNativeFileAdapter::class)->isNativeFile($file)) {
            $mapping = AssistantNativeDocumentRegistry::fileMapping($file);
            if ($mapping === null) { throw new RuntimeException('ai_assistant_document_parent_invalid'); }
            return $mapping['type'];
        }
        $model = $file->fileable;
        $type = $model === null ? null : $this->policy->entityTypeForModel($model);
        if ($type === null || ($model->organization_id !== null && (int) $model->organization_id !== (int) $file->organization_id)) {
            throw new RuntimeException('ai_assistant_document_parent_invalid');
        }
        if ($type === 'design_artifact_version') {
            app(AssistantDesignFileAdapter::class)->assertMapping($file);
        }
        if (AssistantNativeFileRegistry::supports($type)) {
            AssistantNativeFileRegistry::adapter($type)?->assertMapping($file);
        }

        return $type;
    }

    public function resolve(int $organizationId, string $parentType, string|int $parentId, string $path): File
    {
        $file = File::query()->where('organization_id', $organizationId)->where('path', $path)
            ->where('fileable_id', $parentId)->first();
        if ($file === null || $this->parentType($file) !== $parentType || $file->disk !== 's3') {
            throw new RuntimeException('ai_assistant_document_file_invalid');
        }

        return $file;
    }
}
