<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyTransitionEvent;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($subject, $fromStatus, $toStatus, $actorUserId, $occurredAt): SafetyTransitionEvent {
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
                'due_date' => $subject instanceof SafetyIncident ? null : $subject->due_date?->toDateString(),
                'event_version' => $version,
                'evidence_id' => $evidenceId,
                'evidence_type' => $evidenceType,
                'from_status' => $fromStatus,
                'occurred_at' => $occurredAt->format(DATE_ATOM),
                'organization_id' => (int) $subject->organization_id,
                'owner_user_id' => $this->ownerUserId($subject),
                'project_id' => (int) $subject->project_id,
                'safety_site_id' => $siteId,
                'severity' => (string) $subject->severity,
                'subject_id' => (int) $subject->id,
                'subject_type' => $subjectType,
                'to_status' => $toStatus,
            ];

            return SafetyTransitionEvent::query()->create($payload + [
                'event_hash' => hash('sha256', CanonicalJson::encode($payload)),
                'recorded_at' => now(),
            ]);
        });
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

    private function evidence(
        SafetyIncident|SafetyViolation|SafetyCorrectiveAction $subject,
        string $toStatus,
    ): array {
        $metadata = $subject->metadata ?? [];
        $id = match (true) {
            $subject instanceof SafetyCorrectiveAction && $toStatus === 'verified' => $metadata['verification_evidence_id'] ?? null,
            $subject instanceof SafetyIncident && $toStatus === 'closed' => $metadata['investigation_evidence_id'] ?? null,
            $subject instanceof SafetyViolation && $toStatus === 'resolved' => $metadata['resolution_evidence_id'] ?? null,
            default => null,
        };

        if (! is_int($id) && ! (is_string($id) && trim($id) !== '')) {
            return [null, null];
        }

        return ['owner_evidence', (string) $id];
    }
}
