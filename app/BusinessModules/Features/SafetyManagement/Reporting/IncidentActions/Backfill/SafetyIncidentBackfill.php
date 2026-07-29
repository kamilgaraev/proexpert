<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
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
        $inputFacts = [];
        $gaps = 0;
        foreach ($batch['incidents'] ?? [] as $incident) {
            if (! $incident instanceof SafetyIncident) {
                $gaps++;

                continue;
            }
            $inputFacts = [...$inputFacts, ...$this->incidentFacts($incident)];
            $events = [...$events, ...$this->incident($incident)];
        }
        foreach ($batch['violations'] ?? [] as $violation) {
            if (! $violation instanceof SafetyViolation) {
                $gaps++;

                continue;
            }
            $inputFacts = [...$inputFacts, ...$this->violationFacts($violation)];
            $events = [...$events, ...$this->violation($violation)];
        }
        foreach ($batch['actions'] ?? [] as $action) {
            if (! $action instanceof SafetyCorrectiveAction) {
                $gaps++;

                continue;
            }
            $inputFacts = [...$inputFacts, ...$this->actionFacts($action)];
            $events = [...$events, ...$this->action($action)];
        }
        $gaps += abs(count($inputFacts) - count($events));

        return [
            'source_count' => count($inputFacts),
            'projected_count' => count($events),
            'gap_count' => $gaps,
            'unknown_count' => count(array_filter($events, static fn ($event): bool => $event->safety_site_id === null)),
            'input_hash' => hash('sha256', CanonicalJson::encode($inputFacts)),
            'output_hash' => hash('sha256', implode('', array_map(static fn ($event): string => (string) $event->event_hash, $events))),
        ];
    }

    private function incident(SafetyIncident $incident): array
    {
        $events = [$this->recorder->record($incident, null, 'reported', $incident->reported_by_user_id, $incident->occurred_at)];
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

    private function incidentFacts(SafetyIncident $incident): array
    {
        $sourceStateHash = $this->sourceStateHash($incident);
        $facts = [[
            'actor_id' => $incident->reported_by_user_id,
            'from_status' => null,
            'occurred_at' => $incident->occurred_at?->toAtomString(),
            'source_state_hash' => $sourceStateHash,
            'subject_id' => (int) $incident->id,
            'subject_type' => 'incident',
            'to_status' => 'reported',
        ]];
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

        return [
            ...$facts,
            ...$this->stepFacts('incident', (int) $incident->id, $steps, $sourceStateHash),
        ];
    }

    private function violationFacts(SafetyViolation $violation): array
    {
        $sourceStateHash = $this->sourceStateHash($violation);

        return [
            [
                'actor_id' => $violation->created_by_user_id,
                'from_status' => null,
                'occurred_at' => $violation->created_at?->toAtomString(),
                'source_state_hash' => $sourceStateHash,
                'subject_id' => (int) $violation->id,
                'subject_type' => 'violation',
                'to_status' => 'open',
            ],
            ...$this->stepFacts('violation', (int) $violation->id, [
                ['open', 'resolved', $violation->resolved_by_user_id, $violation->resolved_at],
            ], $sourceStateHash),
        ];
    }

    private function actionFacts(SafetyCorrectiveAction $action): array
    {
        $sourceStateHash = $this->sourceStateHash($action);

        return [
            [
                'actor_id' => $action->created_by_user_id,
                'from_status' => null,
                'occurred_at' => $action->created_at?->toAtomString(),
                'source_state_hash' => $sourceStateHash,
                'subject_id' => (int) $action->id,
                'subject_type' => 'corrective_action',
                'to_status' => 'open',
            ],
            ...$this->stepFacts('corrective_action', (int) $action->id, [
                ['open', 'resolved', $action->resolved_by_user_id, $action->resolved_at],
                ['resolved', 'verified', $action->verified_by_user_id, $action->verified_at],
            ], $sourceStateHash),
        ];
    }

    private function stepFacts(
        string $subjectType,
        int $subjectId,
        array $steps,
        string $sourceStateHash,
    ): array
    {
        $facts = [];
        foreach ($steps as [$fromStatus, $toStatus, $actorId, $occurredAt]) {
            if (! $occurredAt instanceof CarbonInterface) {
                continue;
            }
            $facts[] = [
                'actor_id' => $actorId,
                'from_status' => $fromStatus,
                'occurred_at' => $occurredAt->toAtomString(),
                'source_state_hash' => $sourceStateHash,
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'to_status' => $toStatus,
            ];
        }

        return $facts;
    }

    private function sourceStateHash(
        SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject,
    ): string
    {
        $payload = [
            'assigned_to_user_id' => $subject->assigned_to_user_id,
            'contractor_id' => $subject->metadata['contractor_id'] ?? null,
            'due_date' => $subject instanceof SafetyIncident ? null : $subject->due_date?->toDateString(),
            'organization_id' => (int) $subject->organization_id,
            'project_id' => (int) $subject->project_id,
            'safety_site_id' => $subject->metadata['safety_site_id'] ?? null,
            'severity' => (string) $subject->severity,
        ];
        $payload += match (true) {
            $subject instanceof SafetyIncident => [
                'cancelled_at' => $subject->cancelled_at?->toAtomString(),
                'cancellation_reason_hash' => hash('sha256', trim((string) $subject->cancellation_reason)),
                'category' => (string) $subject->incident_type,
                'closed_at' => $subject->closed_at?->toAtomString(),
                'corrective_actions_hash' => hash('sha256', trim((string) $subject->corrective_actions)),
                'root_cause_hash' => hash('sha256', trim((string) $subject->root_cause)),
            ],
            $subject instanceof SafetyViolation => [
                'category' => null,
                'resolution_comment_hash' => hash('sha256', trim((string) $subject->resolution_comment)),
                'resolved_at' => $subject->resolved_at?->toAtomString(),
            ],
            default => [
                'category' => (string) $subject->source_type,
                'resolution_comment_hash' => hash('sha256', trim((string) $subject->resolution_comment)),
                'resolved_at' => $subject->resolved_at?->toAtomString(),
                'verification_comment_hash' => hash('sha256', trim((string) $subject->verification_comment)),
                'verified_at' => $subject->verified_at?->toAtomString(),
            ],
        };

        return hash('sha256', CanonicalJson::encode($payload));
    }
}
