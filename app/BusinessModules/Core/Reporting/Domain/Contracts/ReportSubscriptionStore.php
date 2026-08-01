<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionWindow;
use DateTimeImmutable;

interface ReportSubscriptionStore
{
    public function list(int $organizationId, int $ownerId, ReportSubscriptionWindow $window): ReportSubscriptionPage;

    public function getForActor(int $organizationId, int $ownerId, string $id): ReportSubscription;

    public function lock(string $id): ReportSubscription;

    public function create(ReportSubscription $subscription): ReportSubscription;

    public function updateLocked(ReportSubscription $subscription, array $changes): ReportSubscription;

    public function softDeleteLocked(ReportSubscription $subscription): void;

    public function selectDueLocked(DateTimeImmutable $now, int $limit): array;

    public function advanceNextRunLocked(ReportSubscription $subscription, DateTimeImmutable $nextRun): void;

    public function disableLocked(ReportSubscription $subscription, string $reason): void;
}
