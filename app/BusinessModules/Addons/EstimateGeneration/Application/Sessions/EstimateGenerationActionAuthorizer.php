<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Filament\Support\FilamentPermission;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

use function trans_message;

final readonly class EstimateGenerationActionAuthorizer implements EstimateGenerationActionAuthorization
{
    public function __construct(private AuthorizationService $authorization) {}

    public function authorize(User|SystemAdmin $actor, EstimateGenerationSession $session, string $permission): void
    {
        $project = Project::query()->useWritePdo()->accessibleByOrganization((int) $session->organization_id)
            ->where('is_archived', false)->find($session->project_id);
        if (! $project instanceof Project) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
        $current = $actor->fresh();
        if ($actor instanceof SystemAdmin) {
            if (! $current instanceof SystemAdmin || $permission !== 'estimate_generation.generate'
                || ! $current->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_OPERATE)) {
                throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
            }

            return;
        }
        $context = [
            'organization_id' => (int) $session->organization_id,
            'project_id' => (int) $session->project_id,
        ];

        if (! $current instanceof User || ! $current->is_active
            || (int) $current->current_organization_id !== $context['organization_id']
            || ! $current->organizations()->whereKey($context['organization_id'])->wherePivot('is_active', true)->exists()
            || ! (new UserProjectAccessService)->canAccessProject($current, $project, $context['organization_id'])
            || ! $this->authorization->canCurrent($current, $permission, $context)) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
    }
}
