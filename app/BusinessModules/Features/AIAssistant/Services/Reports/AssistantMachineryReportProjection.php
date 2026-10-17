<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetProjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AssistantMachineryReportProjection
{
    public function __construct(private AssistantDataAccessPolicy $policy) {}

    public function scope(Builder $query, User $actor, int $organizationId, ?int $projectId): Builder
    {
        $canonical = $this->policy->entityQuery($actor, $organizationId, 'organization_asset');
        if ($canonical === null) {
            throw new AccessDeniedHttpException;
        }
        $ids = $canonical->select('organization_assets.id');
        $table = $query->getModel()->getTable();
        $query->where(function (Builder $assets) use ($ids, $table): void {
            if (! config('asset_registry.strict_canonical_reads')) {
                $assets->whereNull($table.'.organization_asset_id');
            }
            $assets->orWhereIn($table.'.organization_asset_id', clone $ids);
        });
        if ($projectId !== null) {
            if (! $this->policy->accessibleProjects($actor, $organizationId)->whereKey($projectId)->exists()) {
                throw new AccessDeniedHttpException;
            }
            $query->where(function (Builder $assets) use ($projectId, $table): void {
                $assets->whereHas('organizationAsset', static fn (Builder $asset): Builder => $asset->where('current_project_id', $projectId))
                    ->orWhere(function (Builder $fallback) use ($projectId, $table): void {
                        $fallback->where($table.'.current_project_id', $projectId)
                            ->where(static fn (Builder $missing): Builder => $missing->whereNull($table.'.organization_asset_id')
                                ->orWhereHas('organizationAsset', static fn (Builder $asset): Builder => $asset->whereNull('current_project_id')));
                    });
            });
        }

        return $query->with(['organizationAsset' => static fn ($asset) => $asset->whereIn('organization_assets.id', clone $ids)->with('operationProfile')]);
    }

    public function row(MachineryAsset $asset): array
    {
        return array_replace($asset->getRawOriginal(), app(MachineryAssetProjection::class)->project($asset));
    }
}
