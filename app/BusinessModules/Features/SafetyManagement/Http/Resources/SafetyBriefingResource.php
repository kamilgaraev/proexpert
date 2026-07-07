<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyBriefing */
final class SafetyBriefingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyBriefing $briefing */
        $briefing = $this->resource;
        $status = $briefing->status ?? 'awaiting_signatures';
        $signatureSummary = $this->signatureSummary($briefing);
        $problemFlags = $this->problemFlags($briefing, $signatureSummary);
        $actions = $this->availableActions($status, $signatureSummary);

        return [
            'id' => $briefing->id,
            'organization_id' => $briefing->organization_id,
            'project_id' => $briefing->project_id,
            'conducted_by_user_id' => $briefing->conducted_by_user_id,
            'completed_by_user_id' => $briefing->completed_by_user_id,
            'cancelled_by_user_id' => $briefing->cancelled_by_user_id,
            'briefing_number' => $briefing->briefing_number,
            'title' => $briefing->title,
            'briefing_type' => $briefing->briefing_type,
            'location_name' => $briefing->location_name,
            'conducted_at' => $briefing->conducted_at?->toIso8601String(),
            'status' => $status,
            'status_label' => trans_message("safety_management.briefing_statuses.{$status}"),
            'started_at' => $briefing->started_at?->toIso8601String(),
            'signature_deadline_at' => $briefing->signature_deadline_at?->toIso8601String(),
            'completed_at' => $briefing->completed_at?->toIso8601String(),
            'cancelled_at' => $briefing->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $briefing->cancellation_reason,
            'signature_summary' => $signatureSummary,
            'workflow_summary' => [
                'stage' => $status,
                'status' => $status,
                'stage_label' => trans_message("safety_management.briefing_statuses.{$status}"),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("safety_management.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => $problemFlags,
                'warnings' => [],
            ],
            'available_actions' => $actions,
            'problem_flags' => $problemFlags,
            'topics' => $briefing->topics ?? [],
            'notes' => $briefing->notes,
            'participants' => $this->whenLoaded(
                'participants',
                fn () => SafetyBriefingParticipantResource::collection($briefing->participants)->resolve()
            ),
            'project' => $this->whenLoaded('project', fn () => $briefing->project ? [
                'id' => $briefing->project->id,
                'name' => $briefing->project->name,
            ] : null),
            'conducted_by_user' => $this->whenLoaded('conductedByUser', fn () => $briefing->conductedByUser ? [
                'id' => $briefing->conductedByUser->id,
                'name' => $briefing->conductedByUser->name,
            ] : null),
            'completed_by_user' => $this->whenLoaded('completedByUser', fn () => $briefing->completedByUser ? [
                'id' => $briefing->completedByUser->id,
                'name' => $briefing->completedByUser->name,
            ] : null),
            'cancelled_by_user' => $this->whenLoaded('cancelledByUser', fn () => $briefing->cancelledByUser ? [
                'id' => $briefing->cancelledByUser->id,
                'name' => $briefing->cancelledByUser->name,
            ] : null),
            'metadata' => $briefing->metadata,
            'created_at' => $briefing->created_at?->toIso8601String(),
            'updated_at' => $briefing->updated_at?->toIso8601String(),
        ];
    }

    private function signatureSummary(SafetyBriefing $briefing): array
    {
        $summary = is_array($briefing->signature_summary) ? $briefing->signature_summary : [];
        $summary = array_merge([
            'total' => 0,
            'pending' => 0,
            'signed' => 0,
            'refused' => 0,
            'absent' => 0,
            'resolved' => 0,
            'completion_percent' => 0,
            'all_resolved' => false,
        ], $summary);

        if ($briefing->relationLoaded('participants')) {
            $total = $briefing->participants->count();
            $pending = $briefing->participants->where('signature_status', 'pending')->count();
            $signed = $briefing->participants->where('signature_status', 'signed')->count();
            $refused = $briefing->participants->where('signature_status', 'refused')->count();
            $absent = $briefing->participants->where('signature_status', 'absent')->count();
            $resolved = $signed + $refused + $absent;

            $summary = array_merge($summary, [
                'total' => $total,
                'pending' => $pending,
                'signed' => $signed,
                'refused' => $refused,
                'absent' => $absent,
                'resolved' => $resolved,
                'completion_percent' => $total === 0 ? 0 : round(($resolved / $total) * 100, 2),
                'all_resolved' => $total > 0 && $pending === 0,
            ]);
        }

        return $summary;
    }

    private function availableActions(string $status, array $signatureSummary): array
    {
        if ($status === 'completed') {
            return ['open_journal'];
        }

        if ($status !== 'awaiting_signatures') {
            return [];
        }

        $actions = ['sign_participant', 'mark_absent', 'mark_refused', 'add_participants', 'cancel'];

        if (($signatureSummary['all_resolved'] ?? false) === true) {
            array_unshift($actions, 'complete');
        }

        return $actions;
    }

    private function problemFlags(SafetyBriefing $briefing, array $signatureSummary): array
    {
        $flags = [];

        if (($signatureSummary['total'] ?? 0) === 0) {
            $flags[] = [
                'code' => 'briefing_without_participants',
                'severity' => 'critical',
                'message' => trans_message('safety_management.problem_flags.briefing_without_participants'),
            ];
        }

        if (($signatureSummary['pending'] ?? 0) > 0) {
            $flags[] = [
                'code' => 'briefing_unsigned_participants',
                'severity' => 'warning',
                'message' => trans_message('safety_management.problem_flags.briefing_unsigned_participants'),
            ];
        }

        if (
            ($signatureSummary['pending'] ?? 0) > 0
            && $briefing->signature_deadline_at !== null
            && $briefing->signature_deadline_at->isPast()
        ) {
            $flags[] = [
                'code' => 'briefing_signature_deadline_overdue',
                'severity' => 'critical',
                'message' => trans_message('safety_management.problem_flags.briefing_signature_deadline_overdue'),
            ];
        }

        if (($signatureSummary['refused'] ?? 0) > 0) {
            $flags[] = [
                'code' => 'briefing_refused_participants',
                'severity' => 'warning',
                'message' => trans_message('safety_management.problem_flags.briefing_refused_participants'),
            ];
        }

        if (($signatureSummary['absent'] ?? 0) > 0) {
            $flags[] = [
                'code' => 'briefing_participant_absent',
                'severity' => 'warning',
                'message' => trans_message('safety_management.problem_flags.briefing_participant_absent'),
            ];
        }

        return $flags;
    }
}
