<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\AdvanceAccountTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CfoDashboardService
{
    private const CACHE_TTL_SECONDS = 60;

    private const ACTIVE_DOCUMENT_STATUSES = [
        PaymentDocumentStatus::SUBMITTED,
        PaymentDocumentStatus::PENDING_APPROVAL,
        PaymentDocumentStatus::APPROVED,
        PaymentDocumentStatus::SCHEDULED,
        PaymentDocumentStatus::PARTIALLY_PAID,
    ];

    public function build(array $filters): array
    {
        $context = $this->normalizeFilters($filters);
        $cacheKey = 'payments:cfo-dashboard:' . sha1(json_encode($context, JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, fn (): array => $this->buildFresh($context));
    }

    private function buildFresh(array $context): array
    {
        $summary = $this->summary($context);
        $cashGap = $this->cashGap($context, $summary);
        $limitOverruns = $this->limitOverruns($context);
        $approvalBlockers = $this->approvalBlockers($context);
        $oneCIssues = $this->oneCIssues($context);

        return [
            'data' => [
                'summary' => $summary,
                'cash_gap' => $cashGap,
                'upcoming_payments' => $this->upcomingDocuments($context, InvoiceDirection::OUTGOING),
                'expected_receipts' => $this->upcomingDocuments($context, InvoiceDirection::INCOMING),
                'limit_overruns' => $limitOverruns,
                'budget_deviations' => $this->budgetDeviations($context),
                'approval_blockers' => $approvalBlockers,
                'one_c_issues' => $oneCIssues,
                'by_projects' => $this->byProjects($context),
                'by_responsibility_centers' => $this->byResponsibilityCenters($context),
                'actions_today' => $this->actionsToday($cashGap, $limitOverruns, $approvalBlockers, $oneCIssues),
            ],
            'meta' => [
                'filters' => $this->publicFilters($context),
                'generated_at' => now()->toIso8601String(),
                'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
                'source_of_truth' => [
                    'payment_documents',
                    'payment_transactions',
                    'advance_account_transactions',
                    'budget_limit_checks',
                ],
            ],
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        $organizationId = (int) ($filters['organization_id'] ?? 0);
        $periodStart = CarbonImmutable::parse((string) $filters['period_start'])->toDateString();
        $periodEnd = CarbonImmutable::parse((string) $filters['period_end'])->toDateString();
        $currency = $filters['currency'] !== null ? mb_strtoupper((string) $filters['currency']) : null;

        return [
            'organization_id' => $organizationId,
            'project_id' => $filters['project_id'] ?? null,
            'responsibility_center_uuid' => $filters['responsibility_center_id'] ?? null,
            'responsibility_center_id' => $this->resolveResponsibilityCenterId(
                $organizationId,
                $filters['responsibility_center_id'] ?? null,
            ),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
            'limit' => (int) ($filters['limit'] ?? 10),
        ];
    }

    private function summary(array $context): array
    {
        $documents = $this->documentBase($context);
        $transactions = $this->transactionBase($context);

        $activeStatuses = $this->activeStatusValues();
        $payable = (clone $documents)
            ->where('direction', InvoiceDirection::OUTGOING->value)
            ->whereIn('status', $activeStatuses)
            ->sum(DB::raw('COALESCE(remaining_amount, amount - paid_amount, amount, 0)'));
        $receivable = (clone $documents)
            ->where('direction', InvoiceDirection::INCOMING->value)
            ->whereIn('status', $activeStatuses)
            ->sum(DB::raw('COALESCE(remaining_amount, amount - paid_amount, amount, 0)'));
        $paidOut = (clone $transactions)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.direction', InvoiceDirection::OUTGOING->value)
            ->when($context['responsibility_center_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_documents.responsibility_center_id', $context['responsibility_center_id']))
            ->sum('payment_transactions.amount');
        $paidIn = (clone $transactions)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.direction', InvoiceDirection::INCOMING->value)
            ->when($context['responsibility_center_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_documents.responsibility_center_id', $context['responsibility_center_id']))
            ->sum('payment_transactions.amount');
        $overduePayable = (clone $documents)
            ->where('direction', InvoiceDirection::OUTGOING->value)
            ->whereIn('status', $activeStatuses)
            ->where(function (QueryBuilder $query): void {
                $query->whereNotNull('overdue_since')
                    ->orWhere('due_date', '<', now()->toDateString());
            })
            ->sum(DB::raw('COALESCE(remaining_amount, amount - paid_amount, amount, 0)'));
        $overdueReceivable = (clone $documents)
            ->where('direction', InvoiceDirection::INCOMING->value)
            ->whereIn('status', $activeStatuses)
            ->where(function (QueryBuilder $query): void {
                $query->whereNotNull('overdue_since')
                    ->orWhere('due_date', '<', now()->toDateString());
            })
            ->sum(DB::raw('COALESCE(remaining_amount, amount - paid_amount, amount, 0)'));

        return [
            'currency' => $context['currency'] ?? 'mixed',
            'payable' => $this->money($payable),
            'receivable' => $this->money($receivable),
            'net_position' => $this->money((float) $receivable - (float) $payable),
            'paid_out' => $this->money($paidOut),
            'paid_in' => $this->money($paidIn),
            'net_cash_flow' => $this->money((float) $paidIn - (float) $paidOut),
            'overdue_payable' => $this->money($overduePayable),
            'overdue_receivable' => $this->money($overdueReceivable),
            'active_documents_count' => (clone $documents)->whereIn('status', $activeStatuses)->count(),
            'pending_approval_count' => (clone $documents)->where('status', PaymentDocumentStatus::PENDING_APPROVAL->value)->count(),
            'limit_overrun_count' => (clone $documents)->whereIn('budget_limit_status', ['exceeded', 'requires_exception'])->count(),
        ];
    }

    private function cashGap(array $context, array $summary): array
    {
        $daily = DB::query()
            ->fromSub($this->cashFlowUnion($context), 'cash_flow')
            ->selectRaw('flow_date')
            ->selectRaw("SUM(CASE WHEN direction = 'incoming' THEN amount ELSE 0 END) AS inflows")
            ->selectRaw("SUM(CASE WHEN direction = 'outgoing' THEN amount ELSE 0 END) AS outflows")
            ->groupBy('flow_date')
            ->orderBy('flow_date')
            ->get();

        $running = (float) $summary['net_cash_flow'];
        $minBalance = $running;
        $firstGapDate = null;
        $series = [];

        foreach ($daily as $row) {
            $running += (float) $row->inflows - (float) $row->outflows;
            $minBalance = min($minBalance, $running);

            if ($firstGapDate === null && $running < 0) {
                $firstGapDate = (string) $row->flow_date;
            }

            $series[] = [
                'date' => (string) $row->flow_date,
                'inflows' => $this->money($row->inflows),
                'outflows' => $this->money($row->outflows),
                'closing_balance' => $this->money($running),
            ];
        }

        return [
            'risk_level' => $this->cashGapRiskLevel($firstGapDate, $minBalance),
            'first_gap_date' => $firstGapDate,
            'min_closing_balance' => $this->money($minBalance),
            'deficit_amount' => $this->money(abs(min(0.0, $minBalance))),
            'series' => $series,
        ];
    }

    private function upcomingDocuments(array $context, InvoiceDirection $direction): array
    {
        return $this->documentBase($context)
            ->leftJoin('projects', 'payment_documents.project_id', '=', 'projects.id')
            ->leftJoin('contractors', 'payment_documents.contractor_id', '=', 'contractors.id')
            ->leftJoin('organizations as counterparties', 'payment_documents.counterparty_organization_id', '=', 'counterparties.id')
            ->where('payment_documents.direction', $direction->value)
            ->whereIn('payment_documents.status', $this->activeStatusValues())
            ->whereBetween('payment_documents.due_date', [$context['period_start'], $context['period_end']])
            ->select([
                'payment_documents.id',
                'payment_documents.document_number',
                'payment_documents.due_date',
                'payment_documents.status',
                'payment_documents.currency',
                'payment_documents.project_id',
                'projects.name as project_name',
                DB::raw('COALESCE(contractors.name, counterparties.name) as counterparty_name'),
                DB::raw('COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) as amount'),
            ])
            ->orderBy('payment_documents.due_date')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'document_number' => (string) $row->document_number,
                'due_date' => (string) $row->due_date,
                'status' => (string) $row->status,
                'amount' => $this->money($row->amount),
                'currency' => (string) $row->currency,
                'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                'project_name' => $row->project_name,
                'counterparty_name' => $row->counterparty_name,
            ])
            ->all();
    }

    private function limitOverruns(array $context): array
    {
        return $this->documentBase($context)
            ->leftJoin('projects', 'payment_documents.project_id', '=', 'projects.id')
            ->leftJoin('responsibility_centers', 'payment_documents.responsibility_center_id', '=', 'responsibility_centers.id')
            ->whereIn('payment_documents.budget_limit_status', ['exceeded', 'requires_exception'])
            ->select([
                'payment_documents.id',
                'payment_documents.document_number',
                'payment_documents.budget_limit_status',
                'payment_documents.budget_limit_decision',
                'payment_documents.budget_limit_message',
                'payment_documents.currency',
                'projects.name as project_name',
                'responsibility_centers.uuid as responsibility_center_id',
                'responsibility_centers.name as responsibility_center_name',
                DB::raw('COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) as amount'),
            ])
            ->orderByDesc('payment_documents.budget_limit_checked_at')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'document_number' => (string) $row->document_number,
                'amount' => $this->money($row->amount),
                'currency' => (string) $row->currency,
                'status' => $row->budget_limit_status,
                'decision' => $row->budget_limit_decision,
                'message' => $row->budget_limit_message,
                'project_name' => $row->project_name,
                'responsibility_center_id' => $row->responsibility_center_id,
                'responsibility_center_name' => $row->responsibility_center_name,
            ])
            ->all();
    }

    private function budgetDeviations(array $context): array
    {
        return DB::query()
            ->fromSub($this->documentBase($context), 'payment_documents')
            ->leftJoin('responsibility_centers', 'payment_documents.responsibility_center_id', '=', 'responsibility_centers.id')
            ->select([
                'responsibility_centers.uuid as responsibility_center_id',
                'responsibility_centers.name as responsibility_center_name',
                'payment_documents.currency',
            ])
            ->selectRaw('COUNT(*) as documents_count')
            ->selectRaw('SUM(COALESCE(payment_documents.amount, 0)) as amount')
            ->selectRaw("SUM(CASE WHEN payment_documents.budget_limit_status IN ('exceeded', 'requires_exception') THEN COALESCE(payment_documents.amount, 0) ELSE 0 END) as exceeded_amount")
            ->whereNotNull('payment_documents.responsibility_center_id')
            ->groupBy('responsibility_centers.uuid', 'responsibility_centers.name', 'payment_documents.currency')
            ->orderByDesc('exceeded_amount')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'responsibility_center_id' => $row->responsibility_center_id,
                'responsibility_center_name' => $row->responsibility_center_name,
                'documents_count' => (int) $row->documents_count,
                'amount' => $this->money($row->amount),
                'exceeded_amount' => $this->money($row->exceeded_amount),
                'currency' => (string) $row->currency,
            ])
            ->all();
    }

    private function approvalBlockers(array $context): array
    {
        $paymentBlockers = $this->documentBase($context)
            ->leftJoin('projects', 'payment_documents.project_id', '=', 'projects.id')
            ->whereIn('payment_documents.status', [
                PaymentDocumentStatus::SUBMITTED->value,
                PaymentDocumentStatus::PENDING_APPROVAL->value,
            ])
            ->select([
                'payment_documents.id',
                'payment_documents.document_number',
                'payment_documents.status',
                'payment_documents.due_date',
                'payment_documents.currency',
                'projects.name as project_name',
                DB::raw('COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) as amount'),
            ])
            ->orderBy('payment_documents.due_date')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'type' => 'payment_document',
                'id' => (int) $row->id,
                'document_number' => (string) $row->document_number,
                'status' => (string) $row->status,
                'due_date' => $row->due_date !== null ? (string) $row->due_date : null,
                'amount' => $this->money($row->amount),
                'currency' => (string) $row->currency,
                'project_name' => $row->project_name,
            ])
            ->all();

        $advanceBlockers = $this->advanceBase($context)
            ->leftJoin('projects', 'advance_account_transactions.project_id', '=', 'projects.id')
            ->where('advance_account_transactions.reporting_status', AdvanceAccountTransaction::STATUS_REPORTED)
            ->select([
                'advance_account_transactions.id',
                'advance_account_transactions.document_number',
                'advance_account_transactions.document_date',
                'advance_account_transactions.amount',
                'advance_account_transactions.reporting_status',
                'projects.name as project_name',
            ])
            ->orderBy('advance_account_transactions.document_date')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'type' => 'advance_account_transaction',
                'id' => (int) $row->id,
                'document_number' => $row->document_number,
                'status' => (string) $row->reporting_status,
                'due_date' => $row->document_date !== null ? (string) $row->document_date : null,
                'amount' => $this->money($row->amount),
                'currency' => $context['currency'] ?? 'RUB',
                'project_name' => $row->project_name,
            ])
            ->all();

        return array_slice([...$paymentBlockers, ...$advanceBlockers], 0, $context['limit']);
    }

    private function oneCIssues(array $context): array
    {
        return $this->advanceBase($context)
            ->leftJoin('projects', 'advance_account_transactions.project_id', '=', 'projects.id')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('advance_account_transactions.external_code')
                    ->orWhere('advance_account_transactions.external_code', '')
                    ->orWhereNotNull('advance_account_transactions.accounting_data');
            })
            ->select([
                'advance_account_transactions.id',
                'advance_account_transactions.document_number',
                'advance_account_transactions.document_date',
                'advance_account_transactions.amount',
                'advance_account_transactions.external_code',
                'advance_account_transactions.accounting_data',
                'projects.name as project_name',
            ])
            ->orderByDesc('advance_account_transactions.updated_at')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'document_number' => $row->document_number,
                'document_date' => $row->document_date !== null ? (string) $row->document_date : null,
                'amount' => $this->money($row->amount),
                'currency' => $context['currency'] ?? 'RUB',
                'project_name' => $row->project_name,
                'has_external_code' => $row->external_code !== null && $row->external_code !== '',
                'has_accounting_payload' => $row->accounting_data !== null,
            ])
            ->all();
    }

    private function byProjects(array $context): array
    {
        return DB::query()
            ->fromSub($this->documentBase($context), 'payment_documents')
            ->leftJoin('projects', 'payment_documents.project_id', '=', 'projects.id')
            ->select(['payment_documents.project_id', 'projects.name as project_name', 'payment_documents.currency'])
            ->selectRaw("SUM(CASE WHEN payment_documents.direction = 'incoming' THEN COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) ELSE 0 END) AS receivable")
            ->selectRaw("SUM(CASE WHEN payment_documents.direction = 'outgoing' THEN COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) ELSE 0 END) AS payable")
            ->selectRaw('COUNT(*) AS documents_count')
            ->groupBy('payment_documents.project_id', 'projects.name', 'payment_documents.currency')
            ->orderByDesc(DB::raw('SUM(COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0))'))
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                'project_name' => $row->project_name,
                'receivable' => $this->money($row->receivable),
                'payable' => $this->money($row->payable),
                'net_position' => $this->money((float) $row->receivable - (float) $row->payable),
                'documents_count' => (int) $row->documents_count,
                'currency' => (string) $row->currency,
            ])
            ->all();
    }

    private function byResponsibilityCenters(array $context): array
    {
        return DB::query()
            ->fromSub($this->documentBase($context), 'payment_documents')
            ->leftJoin('responsibility_centers', 'payment_documents.responsibility_center_id', '=', 'responsibility_centers.id')
            ->whereNotNull('payment_documents.responsibility_center_id')
            ->select([
                'responsibility_centers.uuid as responsibility_center_id',
                'responsibility_centers.name as responsibility_center_name',
                'payment_documents.currency',
            ])
            ->selectRaw("SUM(CASE WHEN payment_documents.direction = 'incoming' THEN COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) ELSE 0 END) AS receivable")
            ->selectRaw("SUM(CASE WHEN payment_documents.direction = 'outgoing' THEN COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) ELSE 0 END) AS payable")
            ->selectRaw('COUNT(*) AS documents_count')
            ->groupBy('responsibility_centers.uuid', 'responsibility_centers.name', 'payment_documents.currency')
            ->orderByDesc('payable')
            ->limit($context['limit'])
            ->get()
            ->map(fn (object $row): array => [
                'responsibility_center_id' => $row->responsibility_center_id,
                'responsibility_center_name' => $row->responsibility_center_name,
                'receivable' => $this->money($row->receivable),
                'payable' => $this->money($row->payable),
                'net_position' => $this->money((float) $row->receivable - (float) $row->payable),
                'documents_count' => (int) $row->documents_count,
                'currency' => (string) $row->currency,
            ])
            ->all();
    }

    private function actionsToday(array $cashGap, array $limitOverruns, array $approvalBlockers, array $oneCIssues): array
    {
        return [
            'cash_gap_requires_attention' => $cashGap['risk_level'] !== 'low',
            'limit_overruns_count' => count($limitOverruns),
            'approval_blockers_count' => count($approvalBlockers),
            'one_c_issues_count' => count($oneCIssues),
        ];
    }

    private function documentBase(array $context): QueryBuilder
    {
        return DB::table('payment_documents')
            ->where('payment_documents.organization_id', $context['organization_id'])
            ->whereNull('payment_documents.deleted_at')
            ->when($context['project_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_documents.project_id', $context['project_id']))
            ->when($context['responsibility_center_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_documents.responsibility_center_id', $context['responsibility_center_id']))
            ->when($context['currency'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_documents.currency', $context['currency']));
    }

    private function transactionBase(array $context): QueryBuilder
    {
        return DB::table('payment_transactions')
            ->where('payment_transactions.organization_id', $context['organization_id'])
            ->where('payment_transactions.status', PaymentTransactionStatus::COMPLETED->value)
            ->whereBetween('payment_transactions.transaction_date', [$context['period_start'], $context['period_end']])
            ->when($context['project_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_transactions.project_id', $context['project_id']))
            ->when($context['currency'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_transactions.currency', $context['currency']));
    }

    private function advanceBase(array $context): QueryBuilder
    {
        return DB::table('advance_account_transactions')
            ->where('advance_account_transactions.organization_id', $context['organization_id'])
            ->whereNull('advance_account_transactions.deleted_at')
            ->whereBetween('advance_account_transactions.document_date', [$context['period_start'], $context['period_end']])
            ->when($context['responsibility_center_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereRaw('1 = 0'))
            ->when($context['project_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('advance_account_transactions.project_id', $context['project_id']));
    }

    private function cashFlowUnion(array $context): QueryBuilder
    {
        $actual = $this->transactionBase($context)
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->when($context['responsibility_center_id'] !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('payment_documents.responsibility_center_id', $context['responsibility_center_id']))
            ->selectRaw('payment_transactions.transaction_date AS flow_date')
            ->selectRaw('payment_documents.direction AS direction')
            ->selectRaw('payment_transactions.amount AS amount');

        $forecast = $this->documentBase($context)
            ->whereIn('payment_documents.status', $this->activeStatusValues())
            ->whereBetween('payment_documents.due_date', [$context['period_start'], $context['period_end']])
            ->selectRaw('payment_documents.due_date AS flow_date')
            ->selectRaw('payment_documents.direction AS direction')
            ->selectRaw('COALESCE(payment_documents.remaining_amount, payment_documents.amount - payment_documents.paid_amount, payment_documents.amount, 0) AS amount');

        return $actual->unionAll($forecast);
    }

    private function resolveResponsibilityCenterId(int $organizationId, mixed $uuid): ?int
    {
        if (!is_string($uuid) || trim($uuid) === '') {
            return null;
        }

        $center = ResponsibilityCenter::query()
            ->where('organization_id', $organizationId)
            ->where('uuid', trim($uuid))
            ->first();

        return $center instanceof ResponsibilityCenter ? (int) $center->id : -1;
    }

    private function publicFilters(array $context): array
    {
        return [
            'organization_id' => $context['organization_id'],
            'project_id' => $context['project_id'],
            'responsibility_center_id' => $context['responsibility_center_uuid'],
            'period_start' => $context['period_start'],
            'period_end' => $context['period_end'],
            'currency' => $context['currency'],
            'limit' => $context['limit'],
        ];
    }

    private function activeStatusValues(): array
    {
        return array_map(
            static fn (PaymentDocumentStatus $status): string => $status->value,
            self::ACTIVE_DOCUMENT_STATUSES,
        );
    }

    private function cashGapRiskLevel(?string $firstGapDate, float $minBalance): string
    {
        if ($firstGapDate !== null) {
            return 'critical';
        }

        if ($minBalance < 0) {
            return 'high';
        }

        return 'low';
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
