<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Backfill;

use App\BusinessModules\Core\MultiOrganization\Reporting\Listeners\ProjectHoldingAllocationFacts;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\AcceptedWorkHoldingFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\ContractAllocationHistory;
use App\Models\ContractPerformanceAct;

final readonly class HoldingPerformanceBackfill
{
    public function __construct(
        private HoldingAllocationFactProjector $projector,
        private AcceptedWorkHoldingFactProducer $acceptedWorkFacts,
        private ProjectHoldingAllocationFacts $paymentFacts,
    ) {}

    public function projectSlice(iterable $sourceRows): array
    {
        $ids = [];
        foreach ($sourceRows as $source) {
            if (! is_array($source)) {
                continue;
            }
            $missing = $this->projector->missingEvidence($source);
            if ($missing !== []) {
                $this->projector->recordGap($source, $missing);

                continue;
            }
            $fact = $this->projector->project($source);
            $record = $this->projector->persist($fact, $source);
            $ids[] = (int) $record->getKey();
        }

        return $ids;
    }

    public function projectAllocationVersions(int $organizationId, int $afterId = 0, int $limit = 500): array
    {
        $versions = ContractAllocationHistory::query()
            ->where('id', '>', $afterId)
            ->whereHas('contract', static fn ($query) => $query->where('organization_id', $organizationId))
            ->with(['contract', 'allocation' => static fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->get();
        $factIds = [];
        foreach ($versions as $version) {
            if ($version->contract !== null && $version->allocation !== null) {
                $fact = $this->projector->recordContractAllocationVersion(
                    $version->contract,
                    $version->allocation,
                    $version,
                );
                if ($fact !== null) {
                    $factIds[] = (int) $fact->getKey();
                }
            }
        }

        return $this->sliceResult($versions->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $factIds, $limit);
    }

    public function projectApprovedActs(int $organizationId, int $afterId = 0, int $limit = 500): array
    {
        $acts = ContractPerformanceAct::query()
            ->where('id', '>', $afterId)
            ->whereHas('contract', static fn ($query) => $query->where('organization_id', $organizationId))
            ->with(['contract.contractor', 'lines', 'completedWorks'])
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->get();
        $factIds = [];
        $gapSourceIds = [];
        foreach ($acts as $act) {
            $active = (bool) $act->is_approved
                && in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true);
            if (! $active) {
                if ($act->approval_date !== null
                    || $act->approved_by_user_id !== null
                    || $act->signed_at !== null) {
                    $this->projector->recordGap([
                        'organization_id' => $organizationId,
                        'source_type' => 'performance_act',
                        'source_id' => (int) $act->getKey(),
                        'source_version' => (int) $act->getKey(),
                        'monetary_basis' => 'accepted_accrual',
                    ], ['accepted_work_event_history'], $act->approval_date ?? $act->created_at ?? now());
                    $gapSourceIds[] = (int) $act->getKey();
                }

                continue;
            }
            $occurredAt = $act->approval_date ?? $act->signed_at ?? $act->created_at ?? now();
            $event = HoldingAcceptedWorkEventVersion::record(
                $act,
                true,
                $occurredAt,
            );
            $fact = $this->acceptedWorkFacts->project(
                $act,
                $occurredAt,
                true,
                (int) $event->getKey(),
            );
            if ($fact !== null) {
                $factIds[] = (int) $fact->getKey();
            }
        }

        return [
            ...$this->sliceResult(
                $acts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                $factIds,
                $limit,
            ),
            'gap_source_ids' => $gapSourceIds,
        ];
    }

    public function projectPaidTransactions(int $organizationId, int $afterId = 0, int $limit = 500): array
    {
        $transactions = PaymentTransaction::query()
            ->where('id', '>', $afterId)
            ->where('organization_id', $organizationId)
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->with('paymentDocument')
            ->orderBy('id')
            ->limit($this->limit($limit))
            ->get();
        $factIds = [];
        foreach ($transactions as $transaction) {
            if ($transaction->paymentDocument === null) {
                continue;
            }
            $before = $transaction->getKey();
            $this->paymentFacts->handle(new PaymentDocumentPaid(
                $transaction->paymentDocument,
                (string) $transaction->amount,
                (int) $transaction->getKey(),
                $transaction->value_date ?? $transaction->transaction_date ?? $transaction->created_at,
            ));
            $factIds[] = (int) $before;
        }

        return $this->sliceResult($transactions->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $factIds, $limit);
    }

    private function sliceResult(array $sourceIds, array $factIds, int $limit): array
    {
        return [
            'source_ids' => $sourceIds,
            'fact_ids' => $factIds,
            'next_cursor' => $sourceIds === [] ? null : max($sourceIds),
            'has_more' => count($sourceIds) === $this->limit($limit),
        ];
    }

    private function limit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }
}
