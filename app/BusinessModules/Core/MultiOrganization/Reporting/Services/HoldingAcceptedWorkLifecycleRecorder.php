<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\Models\ContractPerformanceAct;

final readonly class HoldingAcceptedWorkLifecycleRecorder
{
    public function __construct(
        private AcceptedWorkHoldingFactProducer $facts,
    ) {}

    public function created(ContractPerformanceAct $act): void
    {
        $active = $this->active($act);
        $occurredAt = $active
            ? ($act->approval_date ?? $act->created_at ?? now())
            : ($act->created_at ?? now());
        $event = HoldingAcceptedWorkEventVersion::record(
            $act,
            $active,
            $occurredAt,
            historyComplete: true,
        );
        if ($active) {
            $this->facts->projectEvent($event);
        }
    }

    public function updated(ContractPerformanceAct $act): void
    {
        if (! $act->wasChanged(['status', 'is_approved', 'amount', 'project_id', 'contract_id', 'approval_date'])) {
            return;
        }
        $active = $this->active($act);
        $wasActive = (bool) $act->getOriginal('is_approved')
            && in_array(
                $act->getOriginal('status'),
                [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED],
                true,
            );
        if (! $active && ! $wasActive) {
            return;
        }
        $occurredAt = $active
            ? ($act->approval_date ?? $act->updated_at ?? now())
            : ($act->updated_at ?? now());
        $event = HoldingAcceptedWorkEventVersion::record(
            $act,
            $active,
            $occurredAt,
            historyComplete: $this->historyComplete($act),
        );
        $this->facts->projectEvent($event);
    }

    public function deleted(ContractPerformanceAct $act): void
    {
        if (! (bool) $act->getOriginal('is_approved')
            || ! in_array(
                $act->getOriginal('status'),
                [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED],
                true,
            )) {
            return;
        }
        $event = HoldingAcceptedWorkEventVersion::record(
            $act,
            false,
            now(),
            historyComplete: $this->historyComplete($act),
        );
        $this->facts->projectEvent($event);
    }

    private function active(ContractPerformanceAct $act): bool
    {
        return (bool) $act->is_approved
            && in_array(
                $act->status,
                [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED],
                true,
            );
    }

    private function historyComplete(ContractPerformanceAct $act): bool
    {
        return HoldingAcceptedWorkEventVersion::query()
            ->where('performance_act_id', $act->getKey())
            ->where('history_complete', true)
            ->exists();
    }
}
