<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceGap;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class PortfolioLiquiditySourceVersionRecorder
{
    public function __construct(private PaymentCalendarSourceService $calendar) {}

    public function record(
        Model $source,
        ?DateTimeInterface $occurredAt = null,
        bool $tombstone = false,
    ): ?PortfolioLiquiditySourceVersion {
        $this->loadOwnerRelations($source);
        $identity = $this->identity($source);
        if ($identity === null) {
            return null;
        }
        [$sourceType, $sourceId, $organizationId] = $identity;
        $item = $tombstone ? null : $this->calendarItem($source);
        if (! $tombstone
            && ! $source instanceof CashGapOpeningBalance
            && ! $item instanceof PaymentCalendarItem) {
            $this->recordGap($organizationId, $sourceType, $sourceId, ['canonical_calendar_item']);

            return null;
        }
        $payload = $tombstone ? null : $this->payload($source, $item);
        $effectiveAt = $item instanceof PaymentCalendarItem
            ? CarbonImmutable::parse($item->date)->startOfDay()
            : $this->effectiveAt($source);
        $occurredAt ??= $source->getAttribute('updated_at')
            ?? $source->getAttribute('created_at')
            ?? now();
        $createdAt = $source->getAttribute('created_at') ?? $occurredAt;
        $recordedAt = now();
        $versionPayload = [
            'identity' => [$organizationId, $sourceType, $sourceId],
            'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
            'payload' => $payload,
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($versionPayload));

        $version = PortfolioLiquiditySourceVersion::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_version' => $sourceHash,
            ],
            [
                'occurred_at' => $occurredAt,
                'created_at' => $createdAt,
                'recorded_at' => $recordedAt,
                'effective_at' => $effectiveAt,
                'payload' => $payload,
                'source_hash' => $sourceHash,
            ],
        );
        PortfolioLiquiditySourceGap::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        return $version;
    }

    private function loadOwnerRelations(Model $source): void
    {
        match (true) {
            $source instanceof PaymentSchedule,
            $source instanceof PaymentTransaction,
            $source instanceof BudgetLimitReservation => $source->loadMissing('paymentDocument'),
            $source instanceof BudgetAmount => $source->loadMissing(['line.version', 'line.article']),
            default => null,
        };
    }

    private function calendarItem(Model $source): ?PaymentCalendarItem
    {
        return match (true) {
            $source instanceof PaymentDocument => $this->calendar->fromPaymentDocument($source, $source->updated_at),
            $source instanceof PaymentSchedule => $this->calendar->fromPaymentSchedule($source, $source->updated_at),
            $source instanceof PaymentTransaction => $this->calendar->fromPaymentTransaction($source),
            $source instanceof BudgetLimitReservation => $this->calendar->fromBudgetLimitReservation($source, $source->updated_at),
            $source instanceof BudgetAmount => $this->calendar->fromBudgetAmount($source),
            default => null,
        };
    }

    private function payload(Model $source, ?PaymentCalendarItem $item): ?array
    {
        if ($source instanceof CashGapOpeningBalance) {
            if ($source->status !== CashGapOpeningBalance::STATUS_APPROVED) {
                return null;
            }

            return [
                'kind' => 'opening_balance',
                'id' => (string) $source->getKey(),
                'organization_id' => (int) $source->organization_id,
                'balance_date' => $source->balance_date?->format('Y-m-d'),
                'currency' => mb_strtoupper((string) $source->currency),
                'amount' => (string) $source->amount,
                'status' => (string) $source->status,
                'approved_at' => $source->approved_at?->toIso8601String(),
            ];
        }

        return $item?->toArray();
    }

    private function identity(Model $source): ?array
    {
        $type = match (true) {
            $source instanceof PaymentDocument => 'payment_document',
            $source instanceof PaymentSchedule => 'payment_schedule',
            $source instanceof PaymentTransaction => 'payment_transaction',
            $source instanceof BudgetLimitReservation => 'budget_limit_reservation',
            $source instanceof BudgetAmount => 'budget_amount',
            $source instanceof CashGapOpeningBalance => 'opening_balance',
            default => null,
        };
        $sourceId = $source->getKey();
        $organizationId = $source instanceof BudgetAmount
            ? $source->line?->version?->organization_id
            : $source->getAttribute('organization_id');
        if ($type === null
            || (! is_int($sourceId) && ! is_string($sourceId))
            || ! is_numeric($organizationId)
            || (int) $organizationId < 1) {
            return null;
        }

        return [$type, (string) $sourceId, (int) $organizationId];
    }

    private function effectiveAt(Model $source): DateTimeInterface
    {
        $value = match (true) {
            $source instanceof PaymentDocument => $source->payment_date
                ?? $source->due_date
                ?? $source->document_date,
            $source instanceof PaymentSchedule => $source->due_date,
            $source instanceof PaymentTransaction => $source->value_date ?? $source->transaction_date,
            $source instanceof BudgetLimitReservation => $source->paymentDocument?->payment_date
                ?? $source->paymentDocument?->due_date
                ?? $source->period_month,
            $source instanceof BudgetAmount => $source->month,
            $source instanceof CashGapOpeningBalance => $source->balance_date,
            default => null,
        };

        return $value ?? $source->getAttribute('created_at') ?? now();
    }

    private function recordGap(
        int $organizationId,
        string $sourceType,
        string $sourceId,
        array $missingFields,
    ): void {
        sort($missingFields, SORT_STRING);
        $identity = [
            'organization_id' => $organizationId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'missing_fields' => array_values($missingFields),
        ];

        PortfolioLiquiditySourceGap::query()->firstOrCreate(
            [
                ...$identity,
                'source_hash' => hash('sha256', CanonicalJson::encode($identity)),
            ],
            ['observed_at' => now(), 'resolved_at' => null],
        );
    }
}
