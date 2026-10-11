<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class GetEstimatorCapabilities
{
    public function __construct(private AuthorizationService $authorization) {}

    public function handle(User $actor, int $projectId): array
    {
        $current = $actor->fresh();
        if (! $current instanceof User || ! $current->is_active || (int) $current->current_organization_id !== (int) $actor->current_organization_id) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
        $organizationId = (int) $current->current_organization_id;
        $project = Project::query()->useWritePdo()->accessibleByOrganization($organizationId)->where('is_archived', false)->findOrFail($projectId);
        if (! $current->organizations()->whereKey($organizationId)->wherePivot('is_active', true)->exists()
            || ! (new UserProjectAccessService)->canAccessProject($current, $project, $organizationId)
            || ! $this->authorization->canCurrent($current, 'estimate_generation.create', ['organization_id' => $organizationId, 'project_id' => $projectId])) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }

        return ['universal_available' => (bool) config('estimate-generation.universal_enabled', false)
            && app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProductAccess::class)->available($organizationId),
            'default_evaluation_mode' => 'legacy', 'profiles' => ['construction', 'renovation', 'industrial'],
            'sections' => ['structures', 'finishing', 'roofing', 'earthworks', 'water_supply', 'sewerage', 'heating', 'ventilation',
                'electricity', 'automation', 'technological_pipelines', 'equipment'],
            'price_policies' => ['normative', 'catalog', 'mixed'], 'industrial_profile_verified' => false];
    }
}
