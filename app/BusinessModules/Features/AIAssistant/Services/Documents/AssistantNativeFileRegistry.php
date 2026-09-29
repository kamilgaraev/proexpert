<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

final class AssistantNativeFileRegistry
{
    public static function supports(string $type): bool
    {
        return in_array($type, [...AssistantNativeFileMetadata::types(), ...AssistantLegalNativeFileMetadata::types()], true);
    }

    public static function adapter(string $type): AssistantNativeFileAdapter|AssistantLegalNativeFileAdapter|null
    {
        if (in_array($type, AssistantNativeFileMetadata::types(), true)) {
            return app(AssistantNativeFileAdapter::class);
        }
        if (in_array($type, AssistantLegalNativeFileMetadata::types(), true)) {
            return app(AssistantLegalNativeFileAdapter::class);
        }

        return null;
    }

    public static function permissions(string $type): array
    {
        if (in_array($type, AssistantNativeFileMetadata::types(), true)) {
            return AssistantNativeFileMetadata::PERMISSIONS;
        }

        return AssistantLegalNativeFileMetadata::definitions()[$type]['permissions']
            ?? AssistantSalesNativeFileMetadata::definitions()[$type]['permissions']
            ?? AssistantOperationsNativeFileMetadata::definitions()[$type]['permissions'] ?? [];
    }

    public static function assertIndexable(\App\Models\File $file, string $type): void
    {
        $adapter = self::adapter($type);
        if ($adapter === null) { return; }
        $current = $adapter instanceof AssistantLegalNativeFileAdapter
            ? $adapter->mapForIndexing((int) $file->organization_id, (int) $file->fileable_id, $type)
            : $adapter->mapForIndexing((int) $file->organization_id, (int) $file->fileable_id);
        if ($current === null || (int) $current->id !== (int) $file->id) {
            throw new \RuntimeException('ai_assistant_document_file_changed');
        }
    }
}
