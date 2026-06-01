<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use BackedEnum;

final class DesignViewerConverter
{
    private const DEFAULT_VERSION = 4;

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

    public static function isCurrent(DesignModelDerivative $derivative): bool
    {
        $metadata = is_array($derivative->metadata) ? $derivative->metadata : [];

        return (int) ($metadata['converter_version'] ?? 0) >= self::version();
    }

    public static function isStale(DesignModelDerivative $derivative): bool
    {
        $status = $derivative->status instanceof BackedEnum
            ? $derivative->status->value
            : (string) $derivative->status;

        return $status === DesignDerivativeStatusEnum::READY->value && !self::isCurrent($derivative);
    }

    public static function staleMetadata(array $metadata = []): array
    {
        return array_merge($metadata, [
            'is_stale' => true,
            'required_converter_version' => self::version(),
        ]);
    }
}
