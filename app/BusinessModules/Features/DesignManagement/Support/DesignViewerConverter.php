<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use BackedEnum;
use Illuminate\Http\Request;

final class DesignViewerConverter
{
    private const DEFAULT_VERSION = 4;

    public const IFC_INDEX_SCHEMA_VERSION = 2;

    private const REQUEST_METADATA = '_pir_derivative_decoded_metadata';

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
        $metadata ??= self::metadata($derivative);

        return (int) ($metadata['converter_version'] ?? 0) >= self::version();
    }

    public static function isIndexCurrent(DesignModelDerivative $derivative): bool
    {
        $metadata = self::metadata($derivative);

        return (int) ($metadata['ifc_index_schema_version'] ?? 0) >= self::IFC_INDEX_SCHEMA_VERSION;
    }

    public static function metadata(DesignModelDerivative $derivative, ?Request $request = null): array
    {
        $request ??= app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request || ! $request->isMethod('GET')
            || ! $request->is('api/v1/admin/design-management/packages')) {
            $metadata = $derivative->metadata;

            return is_array($metadata) ? $metadata : [];
        }

        $entries = $request->attributes->get(self::REQUEST_METADATA, []);
        $entries = is_array($entries) ? $entries : [];
        $key = spl_object_id($derivative);
        $raw = $derivative->getAttributes()['metadata'] ?? null;
        $cached = $entries[$key] ?? null;
        if (is_array($cached) && ($cached['model'] ?? null) === $derivative
            && ($cached['raw'] ?? null) === $raw && is_array($cached['metadata'] ?? null)) {
            return $cached['metadata'];
        }

        $metadata = $derivative->metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        $entries[$key] = ['model' => $derivative, 'raw' => $raw, 'metadata' => $metadata];
        $request->attributes->set(self::REQUEST_METADATA, $entries);

        return $metadata;
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
