<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessAuthorizer;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class LaravelLookaheadReadinessAuthorizer implements LookaheadReadinessAuthorizer
{
    public function __construct(private AuthorizationService $authorization) {}

    public function assertAllowed(int $actorId, string $permission, int $organizationId, int $projectId): void
    {
        $actor = User::query()
            ->whereKey($actorId)
            ->whereHas('organizations', static function ($query) use ($organizationId): void {
                $query->where('organizations.id', $organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->first();
        if ($actor === null || ! $this->authorization->can($actor, $permission, array_filter([
            'organization_id' => $organizationId,
            'project_id' => $projectId > 0 ? $projectId : null,
        ]))) {
            throw new AuthorizationException(trans_message('permissions.unauthorized'));
        }
    }
}
