<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final class MobileProjectAccessResolver
{
    public function __construct(
        private readonly UserProjectAccessService $projects,
    ) {}

    public function resolve(
        User $user,
        int $organizationId,
        ?int $projectId,
        string $notFoundMessage,
    ): Project {
        if ($organizationId <= 0 || ($projectId ?? 0) <= 0) {
            throw new DomainException($notFoundMessage);
        }

        return $this->query($user, $organizationId)
            ->whereKey($projectId)
            ->first()
            ?? throw new DomainException($notFoundMessage);
    }

    public function query(User $user, int $organizationId): Builder
    {
        if ($organizationId <= 0) {
            return Project::query()->whereRaw('1 = 0');
        }

        return $this->projects->queryAccessibleProjects($user, $organizationId);
    }

    public function assert(
        User $user,
        int $organizationId,
        int $projectId,
        string $notFoundMessage,
    ): void {
        $this->resolve($user, $organizationId, $projectId, $notFoundMessage);
    }
}
