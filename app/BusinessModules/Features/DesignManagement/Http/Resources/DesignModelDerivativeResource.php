<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignModelDerivativeResource extends JsonResource
{
    private const REQUEST_PROJECTIONS = '_pir_derivative_resource_projections';

    public function resolve($request = null): array
    {
        $request ??= app('request');
        $derivative = $this->resource;
        if (! $request instanceof Request || ! $derivative instanceof DesignModelDerivative
            || ! $request->isMethod('GET') || ! $request->is('api/v1/admin/design-management/packages')) {
            return parent::resolve($request);
        }

        $projections = $request->attributes->get(self::REQUEST_PROJECTIONS, []);
        $projections = is_array($projections) ? $projections : [];
        $key = spl_object_id($derivative);
        $attributes = $derivative->getAttributes();
        $converterVersion = DesignViewerConverter::version();
        $locale = app()->getLocale();
        $cached = $projections[$key] ?? null;
        if (is_array($cached) && ($cached['model'] ?? null) === $derivative
            && ($cached['attributes'] ?? null) === $attributes
            && ($cached['converter_version'] ?? null) === $converterVersion
            && ($cached['locale'] ?? null) === $locale && is_array($cached['payload'] ?? null)) {
            return $cached['payload'];
        }

        $data = $this->toArray($request);
        $preserveKeys = property_exists($this, 'preserveKeys') && $this->preserveKeys === true;
        $payload = $this->filterNativeArrays($data, $preserveKeys) ?? $this->filter($data);
        $projections[$key] = [
            'model' => $derivative,
            'attributes' => $attributes,
            'converter_version' => $converterVersion,
            'locale' => $locale,
            'payload' => $payload,
        ];
        $request->attributes->set(self::REQUEST_PROJECTIONS, $projections);

        return $payload;
    }

    private function filterNativeArrays(array $data, bool $preserveKeys): ?array
    {
        $numericKeys = true;
        foreach ($data as $key => $value) {
            $numericKeys = $numericKeys && is_numeric($key);
            if (is_array($value)) {
                $filtered = $this->filterNativeArrays($value, $preserveKeys);
                if ($filtered === null) {
                    return null;
                }
                if ($filtered !== $value) {
                    $data[$key] = $filtered;
                }
            } elseif ($value !== null && ! is_scalar($value)) {
                return null;
            }
        }

        return ! $preserveKeys && $numericKeys && ! array_is_list($data) ? array_values($data) : $data;
    }

    public function toArray(Request $request): array
    {
        /** @var DesignModelDerivative $derivative */
        $derivative = $this->resource;
        $metadata = DesignViewerConverter::metadata($derivative, $request);
        $isStale = DesignViewerConverter::isStale($derivative, $metadata);
        $status = $isStale ? DesignDerivativeStatusEnum::MISSING->value : $this->enumValue($derivative->status);
        $metadata = DesignViewerConverter::publicMetadata($metadata);
        if ($isStale) {
            $metadata = DesignViewerConverter::staleMetadata($metadata);
        }

        return [
            'id' => $derivative->id,
            'version_id' => $derivative->version_id,
            'viewer_provider' => $derivative->viewer_provider,
            'derivative_format' => $derivative->derivative_format,
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.derivatives.{$status}"),
            'progress_percent' => (int) $derivative->progress_percent,
            'processing_stage' => $derivative->processing_stage,
            'failed_reason' => $derivative->failed_reason,
            'prepared_at' => $derivative->prepared_at?->toIso8601String(),
            'processing_started_at' => $derivative->processing_started_at?->toIso8601String(),
            'processing_finished_at' => $derivative->processing_finished_at?->toIso8601String(),
            'metadata' => $metadata,
            'created_at' => $derivative->created_at?->toIso8601String(),
            'updated_at' => $derivative->updated_at?->toIso8601String(),
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignDerivativeStatusEnum::MISSING->value);
    }
}
