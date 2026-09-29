<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\Models\File;

final class AssistantNativeDocumentRegistry
{
    public static function adapter(string $type): AssistantSalesNativeFileAdapter|AssistantOperationsNativeFileAdapter|null
    {
        if (isset(AssistantSalesNativeFileMetadata::definitions()[$type])) {
            return app(AssistantSalesNativeFileAdapter::class);
        }
        if (isset(AssistantOperationsNativeFileMetadata::definitions()[$type])) {
            return app(AssistantOperationsNativeFileAdapter::class);
        }

        return null;
    }

    public static function forDocument(AIAssistantDocument $document): AssistantSalesNativeFileAdapter|AssistantOperationsNativeFileAdapter|null
    {
        if ($document->file_id !== null) { return null; }
        $type = (string) $document->parent_entity_type;
        $marker = $document->metadata['assistant_native_source'] ?? null;
        if ($marker === AssistantSalesNativeFileMetadata::SOURCE && isset(AssistantSalesNativeFileMetadata::definitions()[$type])) {
            return app(AssistantSalesNativeFileAdapter::class);
        }
        if ($marker === AssistantOperationsNativeFileMetadata::SOURCE && isset(AssistantOperationsNativeFileMetadata::definitions()[$type])) {
            return app(AssistantOperationsNativeFileAdapter::class);
        }

        return null;
    }

    public static function fileMapping(File $file): ?array
    {
        foreach (['safety_medical_exam', 'warehouse_item_gallery'] as $type) {
            $source = AssistantOperationsNativeFileMetadata::sourceQuery($type, (int) $file->organization_id)
                ->where($type === 'safety_medical_exam' ? 'native_file.id' : 'native_source.id', $file->id)
                ->first(AssistantOperationsNativeFileMetadata::sourceColumns($type));
            if ($source !== null) {
                return ['type' => $type, 'source_id' => (string) $source->id, 'parent_id' => (string) $source->native_parent_id];
            }
        }

        return null;
    }
}
