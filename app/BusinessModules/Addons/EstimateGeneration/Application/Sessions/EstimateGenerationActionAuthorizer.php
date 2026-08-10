<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

use function trans_message;

final readonly class EstimateGenerationActionAuthorizer
{
    public function __construct(private AuthorizationService $authorization) {}

    public function authorize(User $actor, EstimateGenerationSession $session, string $permission): void
    {
        $context = [
            'organization_id' => (int) $session->organization_id,
            'project_id' => (int) $session->project_id,
        ];

        if ((int) $actor->current_organization_id !== $context['organization_id']
            || ! $this->authorization->can($actor, $permission, $context)) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
    }
}
