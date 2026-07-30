<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\ReportSnapshotFirstWriter;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyTransitionEvent;
use App\Models\Contractor;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class SafetyTransitionRecorder
{
    public function record(
        SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        DateTimeInterface $occurredAt,
    ): SafetyTransitionEvent {
        return ReportSnapshotFirstWriter::run(
            'safety_transition:'.$subject->organization_id.':'.$this->subjectType($subject).':'.$subject->id,
            function () use ($subject, $fromStatus, $toStatus, $actorUserId, $occurredAt): SafetyTransitionEvent {
                $subjectType = $this->subjectType($subject);
                $existing = SafetyTransitionEvent::query()
                    ->where('organization_id', $subject->organization_id)
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subject->id)
                    ->where('from_status', $fromStatus)
                    ->where('to_status', $toStatus)
                    ->where('occurred_at', $occurredAt)
                    ->first();
                if ($existing instanceof SafetyTransitionEvent) {
                    return $existing;
                }
                $last = SafetyTransitionEvent::query()
                    ->where('organization_id', $subject->organization_id)
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subject->id)
                    ->lockForUpdate()
                    ->orderByDesc('event_version')
                    ->first();
                if ($last instanceof SafetyTransitionEvent && $last->to_status === $toStatus) {
                    return $last;
                }

                $siteId = $this->siteId($subject);
                $version = ($last?->event_version ?? 0) + 1;
                [$evidenceType, $evidenceId] = $this->evidence($subject, $toStatus);
                $payload = [
                    'actor_user_id' => $actorUserId,
                    'category' => $this->category($subject),
                    'contractor_id' => $this->contractorId($subject),
                    'due_date' => $subject instanceof SafetyIncident ? null : $subject->due_date?->toDateString(),
                    'event_version' => $version,
                    'evidence_id' => $evidenceId,
                    'evidence_type' => $evidenceType,
                    'from_status' => $fromStatus,
                    'occurred_at' => $occurredAt->format(DATE_ATOM),
                    'organization_id' => (int) $subject->organization_id,
                    'owner_user_id' => $this->ownerUserId($subject),
                    'project_id' => (int) $subject->project_id,
                    'resolved_at' => $subject instanceof SafetyCorrectiveAction || $subject instanceof SafetyViolation
                        ? $subject->resolved_at?->format(DATE_ATOM)
                        : null,
                    'safety_site_id' => $siteId,
                    'severity' => (string) $subject->severity,
                    'subject_id' => (int) $subject->id,
                    'subject_type' => $subjectType,
                    'to_status' => $toStatus,
                    'verified_at' => $subject instanceof SafetyCorrectiveAction
                        ? $subject->verified_at?->format(DATE_ATOM)
                        : null,
                ];

                return SafetyTransitionEvent::query()->create($payload + [
                    'event_hash' => hash('sha256', CanonicalJson::encode($payload)),
                    'recorded_at' => now(),
                ]);
            },
        );
    }

    private function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof SafetyIncident => 'incident',
            $subject instanceof SafetyViolation => 'violation',
            $subject instanceof SafetyCorrectiveAction => 'corrective_action',
            default => throw new InvalidArgumentException('safety_transition_subject_invalid'),
        };
    }

    private function category(SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject): ?string
    {
        return match (true) {
            $subject instanceof SafetyIncident => (string) $subject->incident_type,
            $subject instanceof SafetyCorrectiveAction => (string) $subject->source_type,
            default => null,
        };
    }

    private function ownerUserId(SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject): ?int
    {
        $value = $subject->assigned_to_user_id;

        return $value === null ? null : (int) $value;
    }

    private function siteId(SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject): ?int
    {
        $siteId = $subject->metadata['safety_site_id'] ?? null;
        if (! is_int($siteId) && ! (is_string($siteId) && ctype_digit($siteId))) {
            return null;
        }

        $siteId = (int) $siteId;
        $matches = SafetySite::query()
            ->whereKey($siteId)
            ->where('organization_id', $subject->organization_id)
            ->where('project_id', $subject->project_id)
            ->exists();

        return $matches ? $siteId : null;
    }

    private function contractorId(SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject): ?int
    {
        $contractorId = $subject->metadata['contractor_id'] ?? null;
        if (! is_int($contractorId) && ! (is_string($contractorId) && ctype_digit($contractorId))) {
            return null;
        }

        $contractorId = (int) $contractorId;

        return Contractor::query()
            ->whereKey($contractorId)
            ->where('organization_id', $subject->organization_id)
            ->exists()
                ? $contractorId
                : null;
    }

    private function evidence(
        SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject,
        string $toStatus,
    ): array {
        $evidence = match (true) {
            $subject instanceof SafetyCorrectiveAction && $toStatus === 'verified'
                && trim((string) $subject->resolution_comment) !== ''
                && trim((string) $subject->verification_comment) !== ''
                && $subject->resolved_at !== null
                && $subject->verified_at !== null => [
                    'type' => 'corrective_action_verification',
                    'value' => [
                        'resolution_comment' => trim((string) $subject->resolution_comment),
                        'resolved_at' => $subject->resolved_at->format(DATE_ATOM),
                        'verification_comment' => trim((string) $subject->verification_comment),
                        'verified_at' => $subject->verified_at->format(DATE_ATOM),
                    ],
                ],
            $subject instanceof SafetyIncident && $toStatus === 'closed'
                && trim((string) $subject->root_cause) !== ''
                && $subject->closed_at !== null => [
                    'type' => 'incident_closure',
                    'value' => [
                        'closed_at' => $subject->closed_at->format(DATE_ATOM),
                        'corrective_actions' => trim((string) $subject->corrective_actions),
                        'root_cause' => trim((string) $subject->root_cause),
                    ],
                ],
            $subject instanceof SafetyIncident && $toStatus === 'cancelled'
                && trim((string) $subject->cancellation_reason) !== ''
                && $subject->cancelled_at !== null => [
                    'type' => 'incident_cancellation',
                    'value' => [
                        'cancelled_at' => $subject->cancelled_at->format(DATE_ATOM),
                        'cancellation_reason' => trim((string) $subject->cancellation_reason),
                    ],
                ],
            $subject instanceof SafetyViolation && $toStatus === 'resolved'
                && trim((string) $subject->resolution_comment) !== ''
                && $subject->resolved_at !== null => [
                    'type' => 'violation_resolution',
                    'value' => [
                        'resolution_comment' => trim((string) $subject->resolution_comment),
                        'resolved_at' => $subject->resolved_at->format(DATE_ATOM),
                    ],
                ],
            default => null,
        };

        if ($evidence === null) {
            return [null, null];
        }

        return [
            $evidence['type'],
            (string) $subject->id,
        ];
    }
}
