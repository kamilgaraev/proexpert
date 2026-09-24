<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SupplierSettlementPlanner
{
    public function build(
        int $orderAmountMinor,
        int $advancePercent,
        int $defermentDays,
        string $advanceDueDate,
        array $receipts
    ): array {
        if ($orderAmountMinor < 0 || $orderAmountMinor > 999999999999999
            || $advancePercent < 0 || $advancePercent > 100
            || $defermentDays < 0 || $defermentDays > 3650
            || ($advancePercent === 100 && $defermentDays !== 0)) {
            throw new InvalidArgumentException('Invalid settlement terms');
        }

        $this->date($advanceDueDate);
        $ids = [];
        foreach ($receipts as $receipt) {
            if (! is_array($receipt)
                || ! is_int($receipt['id'] ?? null) || $receipt['id'] <= 0
                || ! is_int($receipt['amount_minor'] ?? null) || $receipt['amount_minor'] < 0
                || ! is_string($receipt['date'] ?? null)
                || isset($ids[$receipt['id']])) {
                throw new InvalidArgumentException('Invalid settlement receipt');
            }
            $this->date($receipt['date']);
            $ids[$receipt['id']] = true;
        }
        usort($receipts, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        $rows = [];
        $advance = intdiv($orderAmountMinor * $advancePercent + 50, 100);
        if ($advance > 0) {
            $rows[] = ['source_key' => 'advance', 'due_date' => $advanceDueDate, 'amount_minor' => $advance];
        }

        $received = 0;
        $previousDeferred = 0;
        foreach ($receipts as $receipt) {
            if ($receipt['amount_minor'] > $orderAmountMinor - $received) {
                throw new InvalidArgumentException('Settlement receipts exceed order amount');
            }
            $received += $receipt['amount_minor'];
            $cumulativeDeferred = $received - intdiv($received * $advancePercent + 50, 100);
            $amount = $cumulativeDeferred - $previousDeferred;
            $previousDeferred = $cumulativeDeferred;
            if ($amount === 0) {
                continue;
            }
            $dueDate = $this->date($receipt['date'])->modify('+'.$defermentDays.' days')->format('Y-m-d');
            $this->date($dueDate);
            $rows[] = ['source_key' => 'receipt:'.$receipt['id'], 'due_date' => $dueDate, 'amount_minor' => $amount];
        }

        $remaining = $orderAmountMinor - $advance - $previousDeferred;
        if ($remaining > 0) {
            $rows[] = ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => $remaining];
        }

        return $rows;
    }

    private function date(string $value): DateTimeImmutable
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('Invalid settlement date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid settlement date');
        }

        return $date;
    }
}
