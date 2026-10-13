<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantMachineryProjectScope
{
    public function __construct(private AssistantDataAccessPolicy $access) {}
    public function apply(Builder $query, User $actor, int $organizationId, int $projectId): void
    {
        if (! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
        $canonical = $this->access->entityQuery($actor, $organizationId, 'organization_asset');
        if ($canonical === null) { throw new AuthorizationException; }
        if (config('asset_registry.strict_canonical_reads')) {
            $query->whereIn($query->getModel()->getTable().'.organization_asset_id', (clone $canonical)->select('organization_assets.id'));
        }
        $canonical->where('organization_assets.current_project_id', $projectId)->select('organization_assets.id');
        $table = $query->getModel()->getTable();
        $query->where(static fn (Builder $assets): Builder => $assets->where($table.'.current_project_id', $projectId)
            ->orWhereIn($table.'.organization_asset_id', $canonical));
    }
}
