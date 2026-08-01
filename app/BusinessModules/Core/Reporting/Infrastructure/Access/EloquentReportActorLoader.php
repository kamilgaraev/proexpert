<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use InvalidArgumentException;

final readonly class EloquentReportActorLoader implements ReportActorLoader
{
    public function __construct(private AuthorizationService $authorization)
    {
    }

    public function loadActive(int $actorId): ReportActor
    {
        $actor = User::query()
            ->whereKey($actorId)
            ->where('is_active', true)
            ->first();

        if (! $actor instanceof User) {
            throw new InvalidArgumentException('report_actor_not_active');
        }

        return new ReportActor(
            (int) $actor->getKey(),
            'active',
            $this->authorization->getUserPermissions($actor),
        );
    }
}
