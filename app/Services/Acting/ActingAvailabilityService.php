<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\Exceptions\BusinessLogicException;
use App\BusinessModules\Features\HandoverAcceptance\Services\TechnicalAcceptanceQuantityService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\PerformanceActLine;
use App\Services\Pto\PtoWorkspaceQuery;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function trans_message;

class ActingAvailabilityService
{
    public function __construct(
        private readonly PerformanceActFinancialBasisService $financialBasis,
        private readonly CompletedWorkActEligibilityService $completedWorkEligibility,
        private readonly TechnicalAcceptanceQuantityService $technicalAcceptance,
    ) {}

    public function getAvailableWorks(int $contractId, string $periodStart, string $periodEnd): array
    {
        $contract = Contract::query()->findOrFail($contractId);
        $works = $this->baseWorksQuery($contractId, $periodStart, $periodEnd)
            ->orderBy('completion_date')
            ->orderBy('id')
            ->get();
        $quantityUsage = $this->resolveQuantityUsage($works->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $this->mapPeriodWorks($contract, $works, $quantityUsage, true);
    }

    public function getBlockedWorks(int $contractId, string $periodStart, string $periodEnd): array
    {
        $contract = Contract::query()->findOrFail($contractId);
        $works = $this->baseWorksQuery($contractId, $periodStart, $periodEnd)
            ->orderBy('completion_date')
            ->orderBy('id')
            ->get();
        $quantityUsage = $this->resolveQuantityUsage($works->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $this->mapPeriodWorks($contract, $works, $quantityUsage, false);
    }

    /**
     * @param  array<int, array<string, float>>  $quantityUsage
     * @return list<array<string, mixed>>
     */
    private function mapPeriodWorks(Contract $contract, Collection $works, array $quantityUsage, bool $availableOnly): array
    {
        $policy = app(ActingPolicyResolver::class)->resolveForContract($contract);
        $technicalQuantities = $this->technicalAcceptance->acceptedQuantityDecimals($works, $policy);
        $acceptance = $this->technicalAcceptance->acceptanceBreakdown($works);
        $requirementBlockers = $this->requirementBlockersByWork($works);

        return $works
            ->map(fn (CompletedWork $work): array => $this->mapWork(
                $work,
                $contract,
                $quantityUsage[$work->id] ?? [],
                $technicalQuantities[$work->id] ?? null,
                $acceptance[(int) $work->id] ?? null,
                $requirementBlockers[(int) $work->id] ?? [],
            ))
            ->filter(function (array $work) use ($availableOnly): bool {
                $isAvailable = $work['available_to_act'] > 0 && $work['blockers'] === [];

                return $availableOnly ? $isAvailable : ! $isAvailable;
            })
            ->values()
            ->all();
    }

    private function baseWorksQuery(int $contractId, string $periodStart, string $periodEnd)
    {
        return $this->completedWorkEligibility->query($contractId, $periodStart, $periodEnd)
            ->with('estimateItem.contractLinks', 'estimateItem.estimate.currentVersion', 'journalEntry.journal', 'workType')
            ->with(['estimateItem.financeAllocations' => static fn ($query) => $query->where('contract_id', $contractId)->whereNull('resource_id')]);
    }

    private function resolveQuantityUsage(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        /** @var Collection<int, PerformanceActLine> $lines */
        $lines = PerformanceActLine::query()
            ->with('performanceAct')
            ->whereIn('completed_work_id', $workIds)
            ->where('line_type', PerformanceActLine::TYPE_COMPLETED_WORK)
            ->get();

        $usage = [];

        foreach ($lines as $line) {
            $act = $line->performanceAct;

            if (ActingQuantityStatus::isReleased($act)) {
                continue;
            }

            $workId = (int) $line->completed_work_id;
            $usage[$workId] ??= [
                'reserved_quantity' => 0.0,
                'approved_acted_quantity' => 0.0,
            ];

            if (ActingQuantityStatus::isApproved($act)) {
                $usage[$workId]['approved_acted_quantity'] += (float) $line->quantity;

                continue;
            }

            $usage[$workId]['reserved_quantity'] += (float) $line->quantity;
        }

        $legacyReservations = DB::table('performance_act_completed_works as links')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'links.performance_act_id')
            ->whereIn('links.completed_work_id', $workIds)
            ->where(static fn ($query) => $query->whereNull('acts.status')->orWhereNotIn('acts.status', ActingQuantityStatus::releasedStatuses()))
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('performance_act_lines as lines')
                    ->whereColumn('lines.performance_act_id', 'links.performance_act_id')
                    ->whereColumn('lines.completed_work_id', 'links.completed_work_id')
                    ->where('lines.line_type', PerformanceActLine::TYPE_COMPLETED_WORK);
            })
            ->get(['links.completed_work_id', 'links.included_quantity', 'acts.status', 'acts.is_approved']);

        foreach ($legacyReservations as $reservation) {
            $workId = (int) $reservation->completed_work_id;
            $usage[$workId] ??= ['reserved_quantity' => 0.0, 'approved_acted_quantity' => 0.0];
            $key = (bool) $reservation->is_approved || in_array($reservation->status, ActingQuantityStatus::approvedStatuses(), true)
                ? 'approved_acted_quantity' : 'reserved_quantity';
            $usage[$workId][$key] += (float) $reservation->included_quantity;
        }

        foreach ($usage as $workId => $values) {
            $usage[$workId] = [
                'reserved_quantity' => round((float) $values['reserved_quantity'], 4),
                'approved_acted_quantity' => round((float) $values['approved_acted_quantity'], 4),
            ];
        }

        return $usage;
    }

    /**
     * @param  array<string, mixed>|null  $acceptance
     * @param  list<array<string, mixed>>  $requirementBlockers
     * @return array<string, mixed>
     */
    private function mapWork(
        CompletedWork $work,
        Contract $contract,
        array $quantityUsage,
        ?string $technicalQuantity,
        ?array $acceptance,
        array $requirementBlockers,
    ): array {
        $effectiveQuantity = $work->effectiveCompletedQuantity();
        $reservedQuantity = (float) ($quantityUsage['reserved_quantity'] ?? 0);
        $approvedActedQuantity = (float) ($quantityUsage['approved_acted_quantity'] ?? 0);
        $actedQuantity = $reservedQuantity + $approvedActedQuantity;
        $effectiveForActing = $technicalQuantity === null ? BigDecimal::of((string) $effectiveQuantity)
            : BigDecimal::min((string) $effectiveQuantity, $technicalQuantity);
        $availableQuantity = (float) BigDecimal::max('0', $effectiveForActing->minus((string) $actedQuantity))
            ->toScale(4, \Brick\Math\RoundingMode::Down)->__toString();
        $presentedQuantity = (float) ($acceptance['presented_quantity'] ?? $effectiveQuantity);
        $acceptedQuantity = (float) ($acceptance['accepted_quantity'] ?? 0);
        $withRemarksQuantity = (float) ($acceptance['with_remarks_quantity'] ?? 0);
        $blockers = $this->buildBlockers($work, $availableQuantity, $requirementBlockers);
        $unitPrice = 0.0;
        $priceBasis = null;
        try {
            $basis = $this->financialBasis->forCompletedWork($work, $contract, $effectiveQuantity);
            $unitPrice = (float) $basis['unit_price'];
            $priceBasis = [
                'type' => (string) ($basis['snapshot']['basis_type'] ?? 'confirmed_completed_work'),
                'unit_price' => $basis['unit_price'],
                'estimate_version_id' => $basis['estimate_version_id'],
            ];
        } catch (BusinessLogicException $exception) {
            $blockers[] = [
                'code' => 'financial_basis_missing',
                'message' => $exception->getMessage(),
                'target' => 'estimate',
            ];
        }

        return [
            'id' => $work->id,
            'completed_work_id' => (int) $work->id,
            'contract_id' => $work->contract_id,
            'project_id' => $work->project_id,
            'estimate_item_id' => $work->estimate_item_id,
            'estimate_item_name' => $work->estimateItem?->name,
            'estimate_item_position_number' => $work->estimateItem?->position_number,
            'journal_entry_id' => $work->journal_entry_id,
            'journal_entry_number' => $work->journalEntry?->entry_number,
            'journal_number' => $work->journalEntry?->journal?->journal_number,
            'work_origin_type' => $work->work_origin_type,
            'planning_status' => $work->planning_status,
            'work_title' => $work->workType?->name
                ?? $work->estimateItem?->name
                ?? $work->journalEntry?->work_description
                ?? $work->notes,
            'work_type_name' => $work->workType?->name,
            'quantity' => $effectiveQuantity,
            'presented_quantity' => $presentedQuantity,
            'accepted_quantity' => $acceptedQuantity,
            'with_remarks_quantity' => $withRemarksQuantity,
            'technical_accepted_quantity' => $technicalQuantity,
            'acted_quantity' => round($actedQuantity, 4),
            'reserved_quantity' => round($reservedQuantity, 4),
            'approved_acted_quantity' => round($approvedActedQuantity, 4),
            'available_quantity' => $availableQuantity,
            'available_to_act' => $availableQuantity,
            'unit_price' => $unitPrice,
            'available_amount' => round($availableQuantity * $unitPrice, 2),
            'price_basis' => $priceBasis,
            'completion_date' => optional($work->completion_date)->toDateString(),
            'status' => $work->status,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $requirementBlockers
     * @return list<array<string, mixed>>
     */
    private function buildBlockers(CompletedWork $work, float $availableQuantity, array $requirementBlockers): array
    {
        $blockers = $requirementBlockers;

        if ($work->planning_status === CompletedWork::PLANNING_REQUIRES_SCHEDULE) {
            $blockers[] = [
                'code' => 'schedule_missing',
                'message' => trans_message('workflow.blockers.schedule_missing'),
                'target' => 'schedule_missing',
            ];
        }

        if ($availableQuantity <= 0) {
            $blockers[] = [
                'code' => 'already_acted_or_reserved',
                'message' => trans_message('workflow.blockers.already_acted_or_reserved'),
                'target' => 'over_coverage',
            ];
        }

        return $blockers;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function requirementBlockersByWork(Collection $works): array
    {
        $workIds = $works->pluck('id')->map(static fn ($id): int => (int) $id)->filter()->values()->all();
        if ($workIds === [] || ! Schema::hasTable('executive_document_requirements')) {
            return [];
        }

        $requirements = ExecutiveDocumentRequirement::query()
            ->whereIn('completed_work_id', $workIds)
            ->whereNull('superseded_at')
            ->where('applicability', 'required')
            ->whereIn('stage', ['acting', 'technical_acceptance'])
            ->get(['id', 'completed_work_id', 'document_set_id', 'project_id', 'stage', 'title', 'evidence']);

        $grouped = [];
        foreach ($requirements as $requirement) {
            if ((array) $requirement->evidence !== []) {
                continue;
            }
            $workId = (int) $requirement->completed_work_id;
            $requirementId = (int) $requirement->id;
            $grouped[$workId][] = [
                'code' => 'executive_requirement_open',
                'requirement_id' => (string) $requirementId,
                'scope_id' => (int) $requirement->project_id,
                'stage' => (string) $requirement->stage,
                'message' => (string) $requirement->title,
                'target' => ['type' => 'requirement', 'id' => $requirementId],
                'href' => sprintf(
                    '/pto?project_id=%d&section=completeness&category=blocker&source_key=%s&set_id=%d',
                    (int) $requirement->project_id,
                    PtoWorkspaceQuery::requirementSourceKey($requirementId),
                    (int) $requirement->document_set_id,
                ),
            ];
        }

        return $grouped;
    }
}
