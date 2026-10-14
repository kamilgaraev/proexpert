<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

final class TimekeepingState
{
    public const TRANSITIONS = ['submit' => ['draft', 'submitted'], 'verify' => ['submitted', 'verified'], 'approve' => ['verified', 'approved'], 'close' => ['approved', 'closed'], 'reopen' => ['closed', 'draft']];

    public static function next(string $status, string $action): string
    {
        $transition = self::TRANSITIONS[$action] ?? null;
        if ($transition === null || $transition[0] !== $status) {
            throw new \DomainException('timekeeping_transition_invalid');
        }

        return $transition[1];
    }

    public static function editable(string $status, int $actualRevision, int $expectedRevision): void
    {
        if ($actualRevision !== $expectedRevision) {
            throw new \DomainException('timekeeping_revision_conflict');
        }
        if (! in_array($status, ['draft', 'submitted', 'verified'], true)) {
            throw new \DomainException('timekeeping_not_draft');
        }
    }
}
