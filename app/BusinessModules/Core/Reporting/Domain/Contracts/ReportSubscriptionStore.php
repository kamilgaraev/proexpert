<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Domain\Contracts;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription; use DateTimeImmutable;
interface ReportSubscriptionStore { public function getForActor(int $organizationId,int $ownerId,string $id): ReportSubscription; public function lock(string $id): ReportSubscription; public function selectDueLocked(DateTimeImmutable $now,int $limit): array; public function advanceNextRunLocked(ReportSubscription $subscription,DateTimeImmutable $nextRun): void; public function disableLocked(ReportSubscription $subscription,string $reason): void; }
