<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\WorkConstraintTransitionEvent;
use DateTimeImmutable;
use Generator;

final readonly class LookaheadConstraintHistoryStream
{
    public function states(
        ReportScope $scope,
        array $taskIds,
        ?array $constraintIds,
        DateTimeImmutable $asOf,
    ): Generator {
        $events = WorkConstraintTransitionEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('task_id', $taskIds)
            ->when(
                $constraintIds !== null,
                static fn ($builder) => $builder->whereIn('constraint_id', $constraintIds),
            )
            ->where('occurred_at', '<=', $asOf)
            ->orderBy('constraint_id')
            ->orderBy('event_version')
            ->orderBy('id')
            ->cursor();
        $constraintId = null;
        $reducer = null;
        foreach ($events as $event) {
            if ($constraintId !== null && $constraintId !== (int) $event->constraint_id) {
                if (! $reducer instanceof LookaheadConstraintHistoryReducer) {
                    throw new \InvalidArgumentException('lookahead_constraint_history_invalid');
                }
                yield [
                    'task_id' => $reducer->taskId(),
                    'state' => $reducer->finish(),
                ];
                $reducer = null;
            }
            $constraintId = (int) $event->constraint_id;
            $reducer ??= new LookaheadConstraintHistoryReducer;
            $reducer->append($event);
        }
        if ($reducer instanceof LookaheadConstraintHistoryReducer) {
            yield [
                'task_id' => $reducer->taskId(),
                'state' => $reducer->finish(),
            ];
        }
    }
}
