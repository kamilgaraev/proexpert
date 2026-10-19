<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class DesignPackageCollection extends ResourceCollection
{
    public $collects = DesignPackageResource::class;

    public function toArray(Request $request): array
    {
        $packages = $this->collection->map(static fn (DesignPackageResource $resource) => $resource->resource)->all();
        $freshness = $this->measureListPhase($request, 'design_freshness', static fn (): array => app(DesignCompletenessService::class)->freshnessForPackages($packages));

        return $this->measureListPhase($request, 'design_resources', fn (): array => $this->collection->map(static fn (DesignPackageResource $resource): array => DesignPackageResource::withFreshness(
            $resource->resource, $freshness[spl_object_id($resource->resource)],
        )->resolve($request))->all());
    }

    private function measureListPhase(Request $request, string $phase, callable $operation): mixed
    {
        if (! $request->isMethod('GET') || ! $request->is('api/v1/admin/design-management/packages')) {
            return $operation();
        }

        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        try {
            return $operation();
        } finally {
            if ($checkpoint !== null) {
                ApiQueryMetrics::recordProcessingPhase($request, $phase, $checkpoint['started_at'], $checkpoint);
            }
        }
    }
}
