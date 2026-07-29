<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyTransitionRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class SafetyIncidentBackfill
{
    public function __construct(private SafetyTransitionRecorder $recorder) {}

    public function sourceCode(): string
    {
        return 'safety_subject_lifecycle';
    }

    public function sourceSchemaVersion(): string
    {
        return 'safety_incident_actions_v1';
    }

    public function nextBatch(int $organizationId, array $cursor, int $limit = 500): array
    {
        $limit = min(max($limit, 1), 500);

        return [
            'incidents' => SafetyIncident::withTrashed()->where('organization_id', $organizationId)->where('id', '>', (int) ($cursor['incident_id'] ?? 0))->orderBy('id')->limit($limit)->get(),
            'violations' => SafetyViolation::withTrashed()->where('organization_id', $organizationId)->where('id', '>', (int) ($cursor['violation_id'] ?? 0))->orderBy('id')->limit($limit)->get(),
            'actions' => SafetyCorrectiveAction::withTrashed()->where('organization_id', $organizationId)->where('id', '>', (int) ($cursor['action_id'] ?? 0))->orderBy('id')->limit($limit)->get(),
        ];
    }

    public function apply(array $batch): array
    {
        $events = [];
        $gaps = 0;
        foreach ($batch['incidents'] ?? [] as $incident) {
            if (! $incident instanceof SafetyIncident) {
                $gaps++;

                continue;
            }
            $events = [...$events, ...$this->incident($incident)];
        }
        foreach ($batch['violations'] ?? [] as $violation) {
            if (! $violation instanceof SafetyViolation) {
                $gaps++;

                continue;
            }
            $events = [...$events, ...$this->violation($violation)];
        }
        foreach ($batch['actions'] ?? [] as $action) {
            if (! $action instanceof SafetyCorrectiveAction) {
                $gaps++;

                continue;
            }
            $events = [...$events, ...$this->action($action)];
        }
        $sourceCount = collect($batch)->sum(static fn (mixed $rows): int => $rows instanceof Collection ? $rows->count() : 0);

        return [
            'source_count' => $sourceCount,
            'projected_count' => count($events),
            'gap_count' => $gaps,
            'unknown_count' => count(array_filter($events, static fn ($event): bool => $event->safety_site_id === null)),
            'input_hash' => hash('sha256', implode('', array_map(static fn ($event): string => (string) $event->event_hash, $events))),
            'output_hash' => hash('sha256', implode('', array_map(static fn ($event): string => (string) $event->event_hash, $events))),
        ];
    }

    private function incident(SafetyIncident $incident): array
    {
        $events = [$this->recorder->record($incident, null, 'reported', $incident->reported_by_user_id, $incident->created_at)];
        $steps = [
            ['reported', 'triage', $incident->triaged_by_user_id, $incident->triaged_at],
            ['triage', 'investigation', $incident->assigned_to_user_id, $incident->investigation_started_at],
            ['investigation', 'corrective_actions', $incident->assigned_to_user_id, $incident->corrective_actions_started_at],
            [
                $incident->corrective_actions_started_at !== null
                    ? 'corrective_actions'
                    : ($incident->investigation_started_at !== null
                        ? 'investigation'
                        : ($incident->triaged_at !== null ? 'triage' : 'reported')),
                'cancelled',
                $incident->cancelled_by_user_id,
                $incident->cancelled_at,
            ],
            ['corrective_actions', 'closed', $incident->closed_by_user_id, $incident->closed_at],
        ];

        return [...$events, ...$this->steps($incident, $steps)];
    }

    private function violation(SafetyViolation $violation): array
    {
        return [
            $this->recorder->record($violation, null, 'open', $violation->created_by_user_id, $violation->created_at),
            ...$this->steps($violation, [['open', 'resolved', $violation->resolved_by_user_id, $violation->resolved_at]]),
        ];
    }

    private function action(SafetyCorrectiveAction $action): array
    {
        return [
            $this->recorder->record($action, null, 'open', $action->created_by_user_id, $action->created_at),
            ...$this->steps($action, [
                ['open', 'resolved', $action->resolved_by_user_id, $action->resolved_at],
                ['resolved', 'verified', $action->verified_by_user_id, $action->verified_at],
            ]),
        ];
    }

    private function steps(SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject, array $steps): array
    {
        $events = [];
        foreach ($steps as [$from, $to, $actor, $occurredAt]) {
            if ($occurredAt instanceof CarbonInterface) {
                $events[] = $this->recorder->record($subject, $from, $to, $actor, $occurredAt);
            }
        }

        return $events;
    }
}
