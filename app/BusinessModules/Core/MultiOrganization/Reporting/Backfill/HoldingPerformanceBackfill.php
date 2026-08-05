<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Backfill;

use App\BusinessModules\Core\MultiOrganization\Reporting\Listeners\ProjectHoldingAllocationFacts;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
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
            $events = HoldingAcceptedWorkEventVersion::query()
                ->where('performance_act_id', $act->getKey())
                ->orderBy('occurred_at')
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get();
            $historyComplete = $events->isNotEmpty()
                && $events->every(static fn (HoldingAcceptedWorkEventVersion $event): bool => $event->history_complete);
            if (! $historyComplete) {
                $this->projector->recordGap([
                    'organization_id' => $organizationId,
                    'source_type' => 'performance_act',
                    'source_id' => (int) $act->getKey(),
                    'source_version' => (int) ($events->max('id') ?? $act->getKey()),
                    'monetary_basis' => 'accepted_accrual',
                ], ['accepted_work_event_history'], $act->approval_date ?? $act->created_at ?? now());
                $gapSourceIds[] = (int) $act->getKey();

                continue;
            }
            foreach ($events as $event) {
                $fact = HoldingAllocationFactVersion::query()
                    ->where('organization_id', $organizationId)
                    ->where('source_type', 'performance_act')
                    ->where('source_id', $act->getKey())
                    ->where('source_version', $event->getKey())
                    ->where('monetary_basis', 'accepted_accrual')
                    ->first();
                if (! $fact instanceof HoldingAllocationFactVersion) {
                    $this->projector->recordGap([
                        'organization_id' => $organizationId,
                        'source_type' => 'performance_act',
                        'source_id' => (int) $act->getKey(),
                        'source_version' => (int) $event->getKey(),
                        'monetary_basis' => 'accepted_accrual',
                    ], ['accepted_work_fact'], $event->occurred_at ?? now());
                    $gapSourceIds[] = (int) $act->getKey();

                    continue;
                }
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
                document: $transaction->paymentDocument,
                amount: (string) $transaction->amount,
                transactionId: (int) $transaction->getKey(),
                recognizedAt: $transaction->value_date ?? $transaction->transaction_date ?? $transaction->created_at,
                organizationId: (int) $transaction->organization_id,
                projectId: $transaction->project_id === null ? null : (int) $transaction->project_id,
                invoiceableType: $transaction->paymentDocument->invoiceable_type,
                invoiceableId: $transaction->paymentDocument->invoiceable_id === null
                    ? null
                    : (int) $transaction->paymentDocument->invoiceable_id,
                currency: $transaction->currency,
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
