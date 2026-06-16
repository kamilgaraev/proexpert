<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Services;

use App\BusinessModules\Features\Tenders\Exceptions\TenderWorkflowException;
use App\BusinessModules\Features\Tenders\Models\Tender;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class TenderWorkflowService
{
    public function __construct(
        private readonly TenderRegistryService $registry,
        private readonly TenderTimelineService $timeline
    ) {
    }

    public function analyze(Tender $tender, ?int $actorUserId): Tender
    {
        $this->ensureTransition($tender, ['incoming'], []);

        return $this->transition($tender, 'analysis', 'analysis_started', trans_message('tenders.timeline.analysis_started'), $actorUserId);
    }

    public function decideGoNoGo(Tender $tender, array $data, ?int $actorUserId): Tender
    {
        $this->ensureTransition($tender, ['analysis', 'go_no_go'], []);
        $decision = (string) ($data['decision'] ?? $data['go_no_go_decision'] ?? '');

        if (! in_array($decision, ['go', 'no_go'], true)) {
            throw new TenderWorkflowException(trans_message('tenders.workflow.invalid_transition'), [
                ['key' => 'missing_decision', 'label' => trans_message('tenders.blockers.missing_decision')],
            ]);
        }

        return DB::transaction(function () use ($tender, $data, $actorUserId, $decision): Tender {
            $nextStatus = $decision === 'go' ? 'preparation' : 'cancelled';
            $tender->update([
                'status' => $nextStatus,
                'go_no_go_decision' => $decision,
                'go_no_go_reason' => $data['reason'] ?? $data['go_no_go_reason'] ?? null,
                'decided_by_user_id' => $actorUserId,
                'decided_at' => now(),
                'cancel_reason' => $decision === 'no_go' ? ($data['reason'] ?? $data['cancel_reason'] ?? null) : $tender->cancel_reason,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->registry->refreshNextDeadline($tender->refresh());
            $this->timeline->record(
                (int) $tender->organization_id,
                $tender->id,
                'decision_made',
                $decision === 'go' ? trans_message('tenders.timeline.go_decision') : trans_message('tenders.timeline.no_go_decision'),
                $actorUserId
            );

            return $this->registry->find((int) $tender->organization_id, $tender->id);
        });
    }

    public function submit(Tender $tender, array $data, ?int $actorUserId): Tender
    {
        $blockers = [];

        if ($tender->status !== 'preparation') {
            $blockers[] = ['key' => 'invalid_status', 'label' => trans_message('tenders.blockers.invalid_status')];
        }

        if ($tender->submission_deadline_at === null) {
            $blockers[] = ['key' => 'missing_submission_deadline', 'label' => trans_message('tenders.blockers.missing_submission_deadline')];
        }

        if ($blockers !== []) {
            throw new TenderWorkflowException(trans_message('tenders.workflow.invalid_transition'), $blockers);
        }

        return DB::transaction(function () use ($tender, $data, $actorUserId): Tender {
            $tender->update([
                'status' => 'submitted',
                'submitted_at' => $data['submitted_at'] ?? now(),
                'submitted_by_user_id' => $actorUserId,
                'final_bid_amount' => $data['final_bid_amount'] ?? $tender->final_bid_amount,
                'final_bid_amount_missing_reason' => $data['final_bid_amount_missing_reason'] ?? $tender->final_bid_amount_missing_reason,
                'submission_confirmation_url' => $data['submission_confirmation_url'] ?? $tender->submission_confirmation_url,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->registry->refreshNextDeadline($tender->refresh());
            $this->timeline->record((int) $tender->organization_id, $tender->id, 'submitted', trans_message('tenders.timeline.submitted'), $actorUserId);

            return $this->registry->find((int) $tender->organization_id, $tender->id);
        });
    }

    public function recordResult(Tender $tender, array $data, ?int $actorUserId): Tender
    {
        $this->ensureTransition($tender, ['submitted', 'auction_waiting'], []);
        $result = (string) ($data['result'] ?? '');

        if (! in_array($result, ['won', 'lost'], true)) {
            throw new TenderWorkflowException(trans_message('tenders.workflow.invalid_transition'), [
                ['key' => 'missing_result', 'label' => trans_message('tenders.blockers.missing_result')],
            ]);
        }

        return DB::transaction(function () use ($tender, $data, $actorUserId, $result): Tender {
            $tender->update([
                'status' => $result,
                'result_published_at' => $data['result_published_at'] ?? now(),
                'winner_name' => $data['winner_name'] ?? $tender->winner_name,
                'winner_amount' => $data['winner_amount'] ?? $tender->winner_amount,
                'lost_reason' => $result === 'lost' ? ($data['lost_reason'] ?? $tender->lost_reason) : $tender->lost_reason,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->registry->refreshNextDeadline($tender->refresh());
            $this->timeline->record(
                (int) $tender->organization_id,
                $tender->id,
                'result_recorded',
                $result === 'won' ? trans_message('tenders.timeline.won') : trans_message('tenders.timeline.lost'),
                $actorUserId
            );

            return $this->registry->find((int) $tender->organization_id, $tender->id);
        });
    }

    public function cancel(Tender $tender, array $data, ?int $actorUserId): Tender
    {
        $this->ensureTransition($tender, ['incoming', 'analysis', 'go_no_go', 'preparation', 'submitted', 'auction_waiting'], []);

        return DB::transaction(function () use ($tender, $data, $actorUserId): Tender {
            $tender->update([
                'status' => 'cancelled',
                'cancel_reason' => $data['cancel_reason'] ?? $data['reason'] ?? $tender->cancel_reason,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->registry->refreshNextDeadline($tender->refresh());
            $this->timeline->record((int) $tender->organization_id, $tender->id, 'cancelled', trans_message('tenders.timeline.cancelled'), $actorUserId);

            return $this->registry->find((int) $tender->organization_id, $tender->id);
        });
    }

    private function transition(Tender $tender, string $status, string $eventType, string $summary, ?int $actorUserId): Tender
    {
        return DB::transaction(function () use ($tender, $status, $eventType, $summary, $actorUserId): Tender {
            $tender->update([
                'status' => $status,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->registry->refreshNextDeadline($tender->refresh());
            $this->timeline->record((int) $tender->organization_id, $tender->id, $eventType, $summary, $actorUserId);

            return $this->registry->find((int) $tender->organization_id, $tender->id);
        });
    }

    private function ensureTransition(Tender $tender, array $allowedStatuses, array $blockers): void
    {
        if (in_array($tender->status, $allowedStatuses, true) && $blockers === []) {
            return;
        }

        if ($blockers === []) {
            $blockers[] = ['key' => 'invalid_status', 'label' => trans_message('tenders.blockers.invalid_status')];
        }

        throw new TenderWorkflowException(trans_message('tenders.workflow.invalid_transition'), $blockers);
    }
}
