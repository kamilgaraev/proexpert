<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class DesignPackageCollection extends ResourceCollection
{
    public $collects = DesignPackageResource::class;

    public function toArray(Request $request): array
    {
        $packages = $this->collection->map(static fn (DesignPackageResource $resource) => $resource->resource)->all();
        $freshness = app(DesignCompletenessService::class)->freshnessForPackages($packages);

        return $this->collection->map(static fn (DesignPackageResource $resource): array => DesignPackageResource::withFreshness(
            $resource->resource, $freshness[spl_object_id($resource->resource)],
        )->resolve($request))->all();
    }
}
