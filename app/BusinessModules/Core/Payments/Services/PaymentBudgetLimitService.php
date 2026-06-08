<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentAuditLog;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitAmounts;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitCheckContext;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetLimitCheckResult;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetLimitCheckService;
use App\BusinessModules\Features\Budgeting\Services\BudgetWorkflowService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

final class PaymentBudgetLimitService
{
    public const OPERATION_CREATE = 'payment_document_create';
    public const OPERATION_UPDATE = 'payment_document_update';
    public const OPERATION_SUBMIT = 'payment_document_submit';
    public const OPERATION_APPROVAL = 'payment_document_approval';
    public const OPERATION_PAYMENT_REGISTER = 'payment_register';
    public const OPERATION_SCHEDULE = 'payment_document_schedule';
    public const OPERATION_VIEW = 'payment_document_view';

    private const MODULE_SLUG = 'budgeting';

    private const RESERVABLE_STATUSES = [
        PaymentDocumentStatus::SUBMITTED,
        PaymentDocumentStatus::PENDING_APPROVAL,
        PaymentDocumentStatus::APPROVED,
        PaymentDocumentStatus::SCHEDULED,
        PaymentDocumentStatus::PARTIALLY_PAID,
    ];

    private const LEGACY_COMMITTED_STATUSES = [
        PaymentDocumentStatus::SUBMITTED,
        PaymentDocumentStatus::PENDING_APPROVAL,
        PaymentDocumentStatus::APPROVED,
        PaymentDocumentStatus::SCHEDULED,
        PaymentDocumentStatus::PARTIALLY_PAID,
    ];

    public function __construct(
        private readonly BudgetLimitCheckService $limitCheckService,
        private readonly ModulePermissionChecker $modulePermissionChecker,
    ) {
    }

    public function normalizeDocumentData(array $data, int $organizationId): array
    {
        if (array_key_exists('budget_article_id', $data)) {
            $data['budget_article_id'] = $this->resolveCatalogId(
                BudgetArticle::class,
                $organizationId,
                $data['budget_article_id'],
                'budgeting.limits.article_not_found'
            );
        }

        if (array_key_exists('responsibility_center_id', $data)) {
            $data['responsibility_center_id'] = $this->resolveCatalogId(
                ResponsibilityCenter::class,
                $organizationId,
                $data['responsibility_center_id'],
                'budgeting.limits.center_not_found'
            );
        }

        return $data;
    }

    public function check(PaymentDocument $document, ?User $user = null): array
    {
        $calculation = $this->calculate($document, self::OPERATION_VIEW, $this->requestedAmount($document));

        if (!$calculation['controlled']) {
            return $this->neutralPayload((string) $calculation['message']);
        }

        return $this->presentResult($calculation, $user);
    }

    public function assertAllowed(
        PaymentDocument $document,
        string $operationType,
        ?float $requestedAmount = null,
        ?User $user = null,
        ?string $overrideReason = null,
        ?Carbon $operationDate = null,
        bool $lockBudgetLine = false,
    ): ?BudgetLimitCheckResult {
        $calculation = $this->calculate(
            $document,
            $operationType,
            $requestedAmount,
            $operationDate,
            $lockBudgetLine
        );

        return $this->assertCalculationAllowed($document, $calculation, $user, $overrideReason);
    }

    public function syncReservation(
        PaymentDocument $document,
        ?User $user = null,
        ?string $overrideReason = null
    ): void
    {
        DB::transaction(function () use ($document, $user, $overrideReason): void {
            $this->syncReservationInTransaction($document, $user, $overrideReason);
        });
    }

    private function assertCalculationAllowed(
        PaymentDocument $document,
        array $calculation,
        ?User $user,
        ?string $overrideReason,
    ): ?BudgetLimitCheckResult {
        if (!$calculation['controlled']) {
            return null;
        }

        /** @var BudgetLimitCheckResult $result */
        $result = $calculation['result'];
        $isOverride = false;

        if ($result->decision === BudgetLimitCheckService::DECISION_BLOCK) {
            $this->storeResult($document, $calculation, false, $user, null);

            throw new \DomainException($result->message);
        }

        if ($result->decision === BudgetLimitCheckService::DECISION_REQUIRE_EXCEPTION) {
            if (!$this->canOverride($user, $result->requiredPermission, (int) $document->organization_id)) {
                $this->storeResult($document, $calculation, false, $user, null);

                throw new \DomainException($result->message);
            }

            $reason = trim((string) $overrideReason);

            if ($reason === '') {
                $this->storeResult($document, $calculation, false, $user, null);

                throw new \DomainException(trans_message('budgeting.limits.reason_required'));
            }

            $isOverride = true;
            $overrideReason = $reason;
        }

        $this->storeResult($document, $calculation, true, $user, $isOverride ? $overrideReason : null);

        return $result;
    }

    private function syncReservationInTransaction(PaymentDocument $document, ?User $user, ?string $overrideReason): void
    {
        if (!$this->isReservable($document)) {
            $this->release($document, trans_message('budgeting.limits.reserve_not_required'));
            return;
        }

        $calculation = $this->calculate(
            $document,
            self::OPERATION_SUBMIT,
            $this->reservationAmount($document),
            null,
            true
        );

        if (!$calculation['controlled'] || !$calculation['line'] instanceof BudgetLine) {
            $this->release($document, trans_message('budgeting.limits.reserve_not_required'));
            return;
        }

        /** @var BudgetLine $line */
        $line = $calculation['line'];
        /** @var Carbon $month */
        $month = $calculation['month'];
        $amount = $this->reservationAmount($document);

        if ($amount <= 0.0) {
            $this->release($document, trans_message('budgeting.limits.reserve_not_required'));
            return;
        }

        $storedOverrideReason = is_string($document->budget_limit_override_reason ?? null)
            ? $document->budget_limit_override_reason
            : null;

        $this->assertCalculationAllowed(
            $document,
            $calculation,
            $user,
            $overrideReason ?? $storedOverrideReason
        );

        $reservation = BudgetLimitReservation::query()
            ->where('payment_document_id', $document->id)
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)
            ->lockForUpdate()
            ->first();

        $attributes = [
            'organization_id' => (int) $document->organization_id,
            'payment_document_id' => (int) $document->id,
            'budget_limit_check_id' => $this->latestCheckId($document),
            'budget_period_id' => $line->version?->budget_period_id,
            'budget_article_id' => (int) $line->budget_article_id,
            'responsibility_center_id' => (int) $line->responsibility_center_id,
            'project_id' => $document->project_id,
            'contract_id' => $this->contractId($document),
            'counterparty_id' => $this->counterpartyId($document),
            'period_month' => $month->toDateString(),
            'currency' => (string) ($document->currency ?: 'RUB'),
            'amount' => $amount,
            'status' => BudgetLimitReservation::STATUS_RESERVED,
            'reserved_at' => $reservation?->reserved_at ?? now(),
            'released_at' => null,
            'converted_at' => null,
            'release_reason' => null,
            'created_by_user_id' => $user?->id ?? $document->created_by_user_id,
            'metadata' => [
                'document_status' => $document->status?->value,
                'document_number' => $document->document_number,
            ],
        ];

        if ($reservation instanceof BudgetLimitReservation) {
            $reservation->update($attributes);
            return;
        }

        BudgetLimitReservation::query()->create($attributes);
    }

    public function release(PaymentDocument $document, string $reason): void
    {
        BudgetLimitReservation::query()
            ->where('payment_document_id', $document->id)
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)
            ->update([
                'status' => BudgetLimitReservation::STATUS_RELEASED,
                'released_at' => now(),
                'release_reason' => mb_substr($reason, 0, 255),
                'updated_at' => now(),
            ]);
    }

    public function convertAfterPayment(PaymentDocument $document, ?PaymentTransaction $transaction = null): void
    {
        $reservation = BudgetLimitReservation::query()
            ->where('payment_document_id', $document->id)
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)
            ->lockForUpdate()
            ->first();

        if (!$reservation instanceof BudgetLimitReservation) {
            return;
        }

        $remainingAmount = $this->reservationAmount($document);

        if ($document->status === PaymentDocumentStatus::PAID || $remainingAmount <= 0.0) {
            $reservation->update([
                'status' => BudgetLimitReservation::STATUS_CONVERTED,
                'amount' => 0,
                'converted_at' => now(),
                'release_reason' => trans_message('budgeting.limits.reserve_converted'),
                'metadata' => array_merge($reservation->metadata ?? [], [
                    'payment_transaction_id' => $transaction?->id,
                ]),
            ]);

            return;
        }

        $reservation->update([
            'amount' => $remainingAmount,
            'metadata' => array_merge($reservation->metadata ?? [], [
                'last_payment_transaction_id' => $transaction?->id,
            ]),
        ]);
    }

    private function calculate(
        PaymentDocument $document,
        string $operationType,
        ?float $requestedAmount = null,
        ?Carbon $operationDate = null,
        bool $lockBudgetLine = false,
    ): array {
        if (!$this->isBudgetingActive((int) $document->organization_id)) {
            return [
                'controlled' => false,
                'message' => trans_message('budgeting.limits.inactive'),
            ];
        }

        if (!$this->isBudgetControlledDocument($document)) {
            return [
                'controlled' => false,
                'message' => trans_message('budgeting.limits.not_applicable'),
            ];
        }

        $month = $this->operationMonth($document, $operationDate);
        $requested = $requestedAmount ?? $this->requestedAmount($document);
        $budgetArticle = $this->budgetArticle($document);
        $responsibilityCenter = $this->responsibilityCenter($document);

        if (!$budgetArticle instanceof BudgetArticle || !$responsibilityCenter instanceof ResponsibilityCenter) {
            $context = $this->buildContext($document, $operationType, $month, null, $budgetArticle, $responsibilityCenter);
            $amounts = new BudgetLimitAmounts(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, $requested);

            return [
                'controlled' => true,
                'result' => $this->blockedResult('budgeting.limits.dimensions_required', $context, $amounts),
                'line' => null,
                'month' => $month,
                'budget_article' => $budgetArticle,
                'responsibility_center' => $responsibilityCenter,
            ];
        }

        $line = $this->resolveBudgetLine($document, $month, $budgetArticle, $responsibilityCenter, $lockBudgetLine);
        $context = $this->buildContext($document, $operationType, $month, $line, $budgetArticle, $responsibilityCenter);
        $amounts = $this->buildAmounts($document, $month, $line, $requested);

        return [
            'controlled' => true,
            'result' => $this->limitCheckService->check($context, $amounts),
            'line' => $line,
            'month' => $month,
            'budget_article' => $budgetArticle,
            'responsibility_center' => $responsibilityCenter,
        ];
    }

    private function storeResult(
        PaymentDocument $document,
        array $calculation,
        bool $accepted,
        ?User $user,
        ?string $overrideReason,
    ): BudgetLimitCheck {
        /** @var BudgetLimitCheckResult $result */
        $result = $calculation['result'];
        /** @var Carbon $month */
        $month = $calculation['month'];
        /** @var BudgetLine|null $line */
        $line = $calculation['line'];
        /** @var BudgetArticle|null $budgetArticle */
        $budgetArticle = $calculation['budget_article'];
        /** @var ResponsibilityCenter|null $responsibilityCenter */
        $responsibilityCenter = $calculation['responsibility_center'];
        $payload = $this->presentResult($calculation, $user);

        $check = BudgetLimitCheck::query()->create([
            'organization_id' => (int) $document->organization_id,
            'payment_document_id' => $document->id,
            'operation_type' => $result->context->operationType,
            'operation_id' => $result->context->operationId !== null ? (string) $result->context->operationId : null,
            'budget_period_id' => $line?->version?->budget_period_id,
            'budget_article_id' => $budgetArticle?->id ?? $line?->budget_article_id,
            'responsibility_center_id' => $responsibilityCenter?->id ?? $line?->responsibility_center_id,
            'project_id' => $document->project_id,
            'contract_id' => $this->contractId($document),
            'counterparty_id' => $this->counterpartyId($document),
            'period_month' => $month->toDateString(),
            'currency' => (string) ($document->currency ?: 'RUB'),
            'requested_amount' => $result->amounts->requestedAmount,
            'status' => $result->status,
            'decision' => $result->decision,
            'message' => $result->message,
            'required_permission' => $result->requiredPermission,
            'accepted' => $accepted,
            'checked_by_user_id' => $user?->id,
            'overridden_by_user_id' => $overrideReason !== null ? $user?->id : null,
            'override_reason' => $overrideReason,
            'sources' => $payload['sources'],
            'summary' => $payload['summary'],
            'dimensions' => $payload['dimensions'],
            'audit_trail' => $payload['audit_trail'],
        ]);

        $document->forceFill([
            'budget_limit_status' => $result->status,
            'budget_limit_decision' => $result->decision,
            'budget_limit_message' => $result->message,
            'budget_limit_checked_at' => now(),
            'budget_limit_override_reason' => $overrideReason,
            'budget_limit_overridden_by_user_id' => $overrideReason !== null ? $user?->id : null,
        ])->saveQuietly();

        if ($overrideReason !== null) {
            $this->recordOverrideAudit($document, $check, $user, $overrideReason);
        }

        Log::info('payment_document.budget_limit_checked', [
            'document_id' => $document->id,
            'status' => $result->status,
            'decision' => $result->decision,
            'accepted' => $accepted,
        ]);

        return $check;
    }

    private function presentResult(array $calculation, ?User $user): array
    {
        /** @var BudgetLimitCheckResult $result */
        $result = $calculation['result'];
        /** @var BudgetArticle|null $budgetArticle */
        $budgetArticle = $calculation['budget_article'];
        /** @var ResponsibilityCenter|null $responsibilityCenter */
        $responsibilityCenter = $calculation['responsibility_center'];
        $payload = $result->toArray();

        $payload['dimensions'] = array_merge($payload['dimensions'], [
            'budget_article_name' => $budgetArticle?->name,
            'budget_article_code' => $budgetArticle?->code,
            'responsibility_center_name' => $responsibilityCenter?->name,
            'responsibility_center_code' => $responsibilityCenter?->code,
        ]);
        $payload['can_override'] = $this->canOverride(
            $user,
            $result->requiredPermission,
            $result->context->organizationId
        );

        return $payload;
    }

    private function neutralPayload(string $message): array
    {
        return [
            'status' => BudgetLimitCheckService::STATUS_AVAILABLE,
            'decision' => BudgetLimitCheckService::DECISION_ALLOW,
            'message' => $message,
            'summary' => [],
            'sources' => [],
            'dimensions' => [],
            'required_permission' => null,
            'can_override' => false,
        ];
    }

    private function blockedResult(
        string $messageKey,
        BudgetLimitCheckContext $context,
        BudgetLimitAmounts $amounts,
    ): BudgetLimitCheckResult {
        return new BudgetLimitCheckResult(
            BudgetLimitCheckService::STATUS_BLOCKED,
            BudgetLimitCheckService::DECISION_BLOCK,
            trans_message($messageKey),
            $context,
            $amounts,
            null
        );
    }

    private function buildContext(
        PaymentDocument $document,
        string $operationType,
        Carbon $month,
        ?BudgetLine $line,
        ?BudgetArticle $budgetArticle,
        ?ResponsibilityCenter $responsibilityCenter,
    ): BudgetLimitCheckContext {
        $version = $line?->version;
        $period = $version?->period;

        return new BudgetLimitCheckContext(
            operationType: $operationType,
            operationId: $document->getKey(),
            organizationId: (int) $document->organization_id,
            budgetPeriodId: (string) ($period?->uuid ?? 'unassigned'),
            budgetArticleId: (string) ($budgetArticle?->uuid ?? $line?->article?->uuid ?? 'unassigned'),
            responsibilityCenterId: (string) ($responsibilityCenter?->uuid ?? $line?->responsibilityCenter?->uuid ?? 'unassigned'),
            period: $month->format('Y-m'),
            currency: (string) ($document->currency ?: 'RUB'),
            projectId: $document->project_id !== null ? (int) $document->project_id : null,
            contractId: $this->contractId($document),
            counterpartyId: $this->counterpartyId($document),
            limitId: $line?->uuid,
            enforcementMode: $this->enforcementMode($line),
            warningThresholdRatio: $this->warningThresholdRatio($line),
            hasApprovedBudget: $line instanceof BudgetLine,
        );
    }

    private function buildAmounts(
        PaymentDocument $document,
        Carbon $month,
        ?BudgetLine $line,
        float $requestedAmount,
    ): BudgetLimitAmounts {
        if (!$line instanceof BudgetLine) {
            return new BudgetLimitAmounts(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, $requestedAmount);
        }

        return new BudgetLimitAmounts(
            approvedBudgetAmount: $this->approvedBudgetAmount($line, $month, (string) ($document->currency ?: 'RUB')),
            actualPaymentsAmount: $this->actualPaymentsAmount($document, $line, $month),
            pendingApprovalAmount: $this->legacyCommittedAmount($document, $line, $month),
            reservedAmount: $this->reservedAmount($document, $line, $month),
            carryoverAmount: (float) ($line->metadata['carryover_amount'] ?? 0),
            adjustmentAmount: (float) ($line->metadata['adjustment_amount'] ?? 0),
            exceptionAmount: (float) ($line->metadata['exception_amount'] ?? 0),
            requestedAmount: round($requestedAmount, 2),
        );
    }

    private function approvedBudgetAmount(BudgetLine $line, Carbon $month, string $currency): float
    {
        return (float) BudgetAmount::query()
            ->where('budget_line_id', $line->id)
            ->whereDate('month', $month->toDateString())
            ->where('currency', $currency)
            ->sum('plan_amount');
    }

    private function actualPaymentsAmount(PaymentDocument $document, BudgetLine $line, Carbon $month): float
    {
        return (float) PaymentTransaction::query()
            ->where('payment_transactions.organization_id', $document->organization_id)
            ->where('payment_transactions.status', PaymentTransactionStatus::COMPLETED->value)
            ->where('payment_transactions.amount', '>', 0)
            ->whereDate('payment_transactions.transaction_date', '>=', $month->toDateString())
            ->whereDate('payment_transactions.transaction_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->whereHas('paymentDocument', function (Builder $query) use ($document, $line): void {
                $this->applyDocumentDimensionFilter($query, $document, $line);
            })
            ->sum('payment_transactions.amount');
    }

    private function reservedAmount(PaymentDocument $document, BudgetLine $line, Carbon $month): float
    {
        return (float) BudgetLimitReservation::query()
            ->where('organization_id', $document->organization_id)
            ->where('status', BudgetLimitReservation::STATUS_RESERVED)
            ->where('currency', $document->currency ?: 'RUB')
            ->where('budget_article_id', $line->budget_article_id)
            ->where('responsibility_center_id', $line->responsibility_center_id)
            ->whereDate('period_month', $month->toDateString())
            ->where('payment_document_id', '!=', $document->id)
            ->when($line->project_id !== null, fn (Builder $query) => $query->where('project_id', $line->project_id))
            ->when($line->project_id === null, fn (Builder $query) => $query->whereNull('project_id'))
            ->when($line->contract_id !== null, fn (Builder $query) => $query->where('contract_id', $line->contract_id))
            ->when($line->contract_id === null, fn (Builder $query) => $query->whereNull('contract_id'))
            ->when($line->counterparty_id !== null, fn (Builder $query) => $query->where('counterparty_id', $line->counterparty_id))
            ->when($line->counterparty_id === null, fn (Builder $query) => $query->whereNull('counterparty_id'))
            ->sum('amount');
    }

    private function legacyCommittedAmount(PaymentDocument $document, BudgetLine $line, Carbon $month): float
    {
        $statuses = array_map(
            static fn (PaymentDocumentStatus $status): string => $status->value,
            self::LEGACY_COMMITTED_STATUSES
        );

        return (float) PaymentDocument::query()
            ->whereKeyNot($document->getKey())
            ->whereIn('status', $statuses)
            ->where(function (Builder $query) use ($month): void {
                $from = $month->toDateString();
                $to = $month->copy()->endOfMonth()->toDateString();

                $query->whereBetween('scheduled_at', [$from, $to])
                    ->orWhere(function (Builder $dueQuery) use ($from, $to): void {
                        $dueQuery->whereNull('scheduled_at')
                            ->whereBetween('due_date', [$from, $to]);
                    })
                    ->orWhere(function (Builder $documentDateQuery) use ($from, $to): void {
                        $documentDateQuery->whereNull('scheduled_at')
                            ->whereNull('due_date')
                            ->whereBetween('document_date', [$from, $to]);
                    });
            })
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('budget_limit_reservations')
                    ->whereColumn('budget_limit_reservations.payment_document_id', 'payment_documents.id')
                    ->where('budget_limit_reservations.status', BudgetLimitReservation::STATUS_RESERVED);
            })
            ->tap(fn (Builder $query) => $this->applyDocumentDimensionFilter($query, $document, $line))
            ->sum('remaining_amount');
    }

    private function applyDocumentDimensionFilter(Builder $query, PaymentDocument $document, BudgetLine $line): void
    {
        $query->where('organization_id', $document->organization_id)
            ->where('currency', $document->currency ?: 'RUB')
            ->where('budget_article_id', $line->budget_article_id)
            ->where('responsibility_center_id', $line->responsibility_center_id)
            ->where(function (Builder $builder): void {
                $builder->whereNull('direction')
                    ->orWhere('direction', '!=', InvoiceDirection::INCOMING->value);
            });

        $this->applyNullableDimension($query, 'project_id', $line->project_id);
        $this->applyNullableDimension($query, 'contract_id', $line->contract_id);
        $this->applyNullableDimension($query, 'counterparty_id', $line->counterparty_id);
    }

    private function applyNullableDimension(Builder $query, string $dimension, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if ($dimension === 'contract_id') {
            $query->where(function (Builder $builder) use ($value): void {
                $builder->where(function (Builder $nested) use ($value): void {
                    $nested->where('invoiceable_type', Contract::class)
                        ->where('invoiceable_id', $value);
                })->orWhere(function (Builder $nested) use ($value): void {
                    $nested->where('source_type', Contract::class)
                        ->where('source_id', $value);
                });
            });

            return;
        }

        if ($dimension === 'counterparty_id') {
            $query->where(function (Builder $builder) use ($value): void {
                $builder->where('contractor_id', $value)
                    ->orWhere('payee_contractor_id', $value)
                    ->orWhere('payer_contractor_id', $value);
            });

            return;
        }

        $query->where($dimension, $value);
    }

    private function resolveBudgetLine(
        PaymentDocument $document,
        Carbon $month,
        BudgetArticle $budgetArticle,
        ResponsibilityCenter $responsibilityCenter,
        bool $lockBudgetLine = false,
    ): ?BudgetLine {
        $contractId = $this->contractId($document);
        $counterpartyId = $this->counterpartyId($document);

        return BudgetLine::query()
            ->with(['version.period', 'article', 'responsibilityCenter'])
            ->where('budget_article_id', $budgetArticle->id)
            ->where('responsibility_center_id', $responsibilityCenter->id)
            ->where('currency', $document->currency ?: 'RUB')
            ->whereHas('version', function (Builder $query) use ($document, $month): void {
                $query->where('organization_id', $document->organization_id)
                    ->whereIn('status', [
                        BudgetWorkflowService::STATUS_APPROVED,
                        BudgetWorkflowService::STATUS_ACTIVE,
                    ])
                    ->whereIn('budget_kind', ['bdds', 'consolidated'])
                    ->whereHas('period', function (Builder $periodQuery) use ($month): void {
                        $periodQuery->whereDate('starts_at', '<=', $month->toDateString())
                            ->whereDate('ends_at', '>=', $month->toDateString());
                    });
            })
            ->where(function (Builder $builder) use ($document): void {
                $builder->whereNull('project_id');

                if ($document->project_id !== null) {
                    $builder->orWhere('project_id', $document->project_id);
                }
            })
            ->where(function (Builder $builder) use ($contractId): void {
                $builder->whereNull('contract_id');

                if ($contractId !== null) {
                    $builder->orWhere('contract_id', $contractId);
                }
            })
            ->where(function (Builder $builder) use ($counterpartyId): void {
                $builder->whereNull('counterparty_id');

                if ($counterpartyId !== null) {
                    $builder->orWhere('counterparty_id', $counterpartyId);
                }
            })
            ->orderByRaw('CASE WHEN project_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN contract_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN counterparty_id IS NULL THEN 0 ELSE 1 END DESC')
            ->when($lockBudgetLine, fn (Builder $query) => $query->lockForUpdate())
            ->orderByDesc('id')
            ->first();
    }

    private function isBudgetingActive(int $organizationId): bool
    {
        return $organizationId > 0
            && $this->modulePermissionChecker->isModuleActive(self::MODULE_SLUG, $organizationId);
    }

    private function isBudgetControlledDocument(PaymentDocument $document): bool
    {
        if ((float) $document->amount <= 0.0) {
            return false;
        }

        if ($document->direction === InvoiceDirection::INCOMING) {
            return false;
        }

        return in_array($document->document_type, [
            PaymentDocumentType::PAYMENT_REQUEST,
            PaymentDocumentType::INVOICE,
            PaymentDocumentType::PAYMENT_ORDER,
            PaymentDocumentType::EXPENSE,
        ], true);
    }

    private function isReservable(PaymentDocument $document): bool
    {
        return in_array($document->status, self::RESERVABLE_STATUSES, true)
            && $this->reservationAmount($document) > 0.0
            && $this->isBudgetControlledDocument($document);
    }

    private function operationMonth(PaymentDocument $document, ?Carbon $operationDate = null): Carbon
    {
        $date = $operationDate
            ?? $document->scheduled_at
            ?? $document->due_date
            ?? $document->document_date
            ?? now();

        return Carbon::parse($date)->startOfMonth();
    }

    private function requestedAmount(PaymentDocument $document): float
    {
        if ($document->status === PaymentDocumentStatus::PARTIALLY_PAID) {
            return $this->reservationAmount($document);
        }

        return round((float) $document->amount, 2);
    }

    private function reservationAmount(PaymentDocument $document): float
    {
        $remaining = $document->remaining_amount;

        if ($remaining === null) {
            return round((float) $document->amount, 2);
        }

        return round(max(0.0, (float) $remaining), 2);
    }

    private function budgetArticle(PaymentDocument $document): ?BudgetArticle
    {
        if ($document->relationLoaded('budgetArticle')) {
            return $document->budgetArticle;
        }

        return $document->budget_article_id !== null
            ? BudgetArticle::query()->find((int) $document->budget_article_id)
            : null;
    }

    private function responsibilityCenter(PaymentDocument $document): ?ResponsibilityCenter
    {
        if ($document->relationLoaded('responsibilityCenter')) {
            return $document->responsibilityCenter;
        }

        return $document->responsibility_center_id !== null
            ? ResponsibilityCenter::query()->find((int) $document->responsibility_center_id)
            : null;
    }

    private function contractId(PaymentDocument $document): ?int
    {
        if ($document->invoiceable_type === Contract::class && $document->invoiceable_id) {
            return (int) $document->invoiceable_id;
        }

        if ($document->source_type === Contract::class && $document->source_id) {
            return (int) $document->source_id;
        }

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $value = $metadata['contract_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function counterpartyId(PaymentDocument $document): ?int
    {
        return $document->contractor_id
            ?? $document->payee_contractor_id
            ?? $document->payer_contractor_id;
    }

    private function enforcementMode(?BudgetLine $line): string
    {
        $value = $line?->metadata['enforcement_mode'] ?? config(
            'payments.budget_limit.enforcement_mode',
            BudgetLimitCheckContext::ENFORCEMENT_SOFT_BLOCK
        );

        return (string) $value;
    }

    private function warningThresholdRatio(?BudgetLine $line): float
    {
        return (float) ($line?->metadata['warning_threshold_ratio']
            ?? config('payments.budget_limit.warning_threshold_ratio', 0.9));
    }

    private function canOverride(?User $user, ?string $permission, int $organizationId): bool
    {
        return $user !== null
            && $permission !== null
            && $user->can($permission, ['organization_id' => $organizationId]);
    }

    private function latestCheckId(PaymentDocument $document): ?int
    {
        return BudgetLimitCheck::query()
            ->where('payment_document_id', $document->id)
            ->latest('id')
            ->value('id');
    }

    private function resolveCatalogId(string $modelClass, int $organizationId, mixed $value, string $messageKey): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $model = $modelClass::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($value): void {
                if (is_numeric($value)) {
                    $query->whereKey((int) $value);
                }

                $query->orWhere('uuid', (string) $value);
            })
            ->first();

        if (!$model) {
            throw new \DomainException(trans_message($messageKey));
        }

        return (int) $model->id;
    }

    private function recordOverrideAudit(
        PaymentDocument $document,
        BudgetLimitCheck $check,
        ?User $user,
        string $reason,
    ): void {
        PaymentAuditLog::query()->create([
            'payment_document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'action' => 'budget_limit_override',
            'entity_type' => PaymentDocument::class,
            'entity_id' => $document->id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_role' => null,
            'old_values' => null,
            'new_values' => [
                'budget_limit_check_id' => $check->id,
                'reason' => $reason,
            ],
            'changed_fields' => ['budget_limit_override'],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'description' => trans_message('budgeting.limits.override_recorded'),
            'metadata' => [
                'budget_limit_status' => $check->status,
                'budget_limit_decision' => $check->decision,
                'budget_limit_check_uuid' => $check->uuid,
            ],
        ]);
    }
}
