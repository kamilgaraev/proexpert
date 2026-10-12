<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use BackedEnum;

final class DesignViewerConverter
{
    private const DEFAULT_VERSION = 4;

    public const IFC_INDEX_SCHEMA_VERSION = 2;

    public static function version(): int
    {
        return max(1, (int) config('design_management.viewer_converter_version', self::DEFAULT_VERSION));
    }

    public static function preparedMetadata(array $metadata = [], array $extra = []): array
    {
        return array_merge($metadata, $extra, [
            'prepared_on' => 'server',
            'converter_version' => self::version(),
        ]);
    }

    public static function isCurrent(DesignModelDerivative $derivative, ?array $metadata = null): bool
    {
        $metadata ??= $derivative->metadata;
        $metadata = is_array($metadata) ? $metadata : [];

        return (int) ($metadata['converter_version'] ?? 0) >= self::version();
    }

    public static function isIndexCurrent(DesignModelDerivative $derivative): bool
    {
        $metadata = is_array($derivative->metadata) ? $derivative->metadata : [];

        return (int) ($metadata['ifc_index_schema_version'] ?? 0) >= self::IFC_INDEX_SCHEMA_VERSION;
    }

    public static function publicMetadata(array $metadata): array
    {
        unset($metadata['coordinate_transformations']);

        if (isset($metadata['ifc_metadata']) && is_array($metadata['ifc_metadata'])) {
            unset($metadata['ifc_metadata']['transformations']);
        }

        return $metadata;
    }

    public static function isStale(DesignModelDerivative $derivative, ?array $metadata = null): bool
    {
        $status = $derivative->status instanceof BackedEnum
            ? $derivative->status->value
            : (string) $derivative->status;

        return $status === DesignDerivativeStatusEnum::READY->value && ! self::isCurrent($derivative, $metadata);
    }

    public static function staleMetadata(array $metadata = []): array
    {
        return array_merge($metadata, [
            'is_stale' => true,
            'required_converter_version' => self::version(),
            'required_ifc_index_schema_version' => self::IFC_INDEX_SCHEMA_VERSION,
        ]);
    }
}
