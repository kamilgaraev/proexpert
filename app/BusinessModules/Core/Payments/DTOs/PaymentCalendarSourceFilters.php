<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\DTOs;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class PaymentCalendarSourceFilters
{
    public int $organizationId;
    public string $periodStart;
    public string $periodEnd;
    public ?int $projectId;
    public ?int $counterpartyId;
    public int|string|null $budgetArticleId;
    public int|string|null $responsibilityCenterId;
    public ?string $currency;
    public ?string $direction;
    public ?string $bucket;
    public ?string $sourceType;

    public function __construct(
        int $organizationId,
        string|DateTimeInterface $periodStart,
        string|DateTimeInterface $periodEnd,
        ?int $projectId = null,
        ?int $counterpartyId = null,
        int|string|null $budgetArticleId = null,
        int|string|null $responsibilityCenterId = null,
        ?string $currency = null,
        ?string $direction = null,
        ?string $bucket = null,
        ?string $sourceType = null,
    ) {
        $this->organizationId = $organizationId;
        $this->periodStart = $this->dateString($periodStart);
        $this->periodEnd = $this->dateString($periodEnd);
        $this->projectId = $projectId;
        $this->counterpartyId = $counterpartyId;
        $this->budgetArticleId = $budgetArticleId;
        $this->responsibilityCenterId = $responsibilityCenterId;
        $this->currency = $currency === null ? null : mb_strtoupper($currency);
        $this->direction = $this->normalizeNullableString($direction);
        $this->bucket = $this->normalizeNullableString($bucket);
        $this->sourceType = $this->normalizeNullableString($sourceType);
    }

    public function matches(PaymentCalendarItem $item): bool
    {
        if ($item->organizationId !== $this->organizationId) {
            return false;
        }

        if (!$this->dateMatches($item)) {
            return false;
        }

        if ($this->projectId !== null && $item->projectId !== $this->projectId) {
            return false;
        }

        if ($this->counterpartyId !== null && $item->counterpartyId !== $this->counterpartyId) {
            return false;
        }

        if (
            $this->budgetArticleId !== null
            && (string) $item->budgetArticleId !== (string) $this->budgetArticleId
        ) {
            return false;
        }

        if (
            $this->responsibilityCenterId !== null
            && (string) $item->responsibilityCenterId !== (string) $this->responsibilityCenterId
        ) {
            return false;
        }

        if ($this->currency !== null && mb_strtoupper($item->currency) !== $this->currency) {
            return false;
        }

        if ($this->direction !== null && $item->direction !== $this->direction) {
            return false;
        }

        if ($this->bucket !== null && $item->bucket !== $this->bucket) {
            return false;
        }

        return $this->sourceType === null || $item->sourceType === $this->sourceType;
    }

    public function periodStartMonth(): string
    {
        return (new DateTimeImmutable($this->periodStart))
            ->modify('first day of this month')
            ->format('Y-m-d');
    }

    public function periodEndMonth(): string
    {
        return (new DateTimeImmutable($this->periodEnd))
            ->modify('first day of this month')
            ->format('Y-m-d');
    }

    private function dateMatches(PaymentCalendarItem $item): bool
    {
        $date = new DateTimeImmutable($item->date);
        $start = new DateTimeImmutable($this->periodStart);
        $end = new DateTimeImmutable($this->periodEnd);

        if ($this->monthlySourceMatches($item, $date, $start, $end)) {
            return true;
        }

        if ($item->bucket === PaymentCalendarItem::BUCKET_OVERDUE) {
            return $date <= $end;
        }

        return $date >= $start && $date <= $end;
    }

    private function monthlySourceMatches(
        PaymentCalendarItem $item,
        DateTimeImmutable $date,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): bool
    {
        if (!$this->isMonthlySource($item)) {
            return false;
        }

        $monthStart = $date->modify('first day of this month');
        $monthEnd = $date->modify('last day of this month');

        return $monthStart <= $end && $monthEnd >= $start;
    }

    private function isMonthlySource(PaymentCalendarItem $item): bool
    {
        if ($item->bucket === PaymentCalendarItem::BUCKET_BUDGET_PLAN) {
            return true;
        }

        return $item->sourceType === 'budget_limit_reservation'
            && ($item->drillDown['payment_document_id'] ?? null) === null;
    }

    private function dateString(string|DateTimeInterface $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return (new DateTimeImmutable($date))->format('Y-m-d');
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
