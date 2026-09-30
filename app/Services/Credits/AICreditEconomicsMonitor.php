<?php

declare(strict_types=1);

namespace App\Services\Credits;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class AICreditEconomicsMonitor
{
    public function report(CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($from->greaterThanOrEqualTo($until)) { throw new InvalidArgumentException('economics_period_invalid'); }
        $required = ['ai_credit_provider_usages', 'ai_usage_records', 'commercial_orders', 'commercial_payments', 'commercial_refunds', 'ai_credit_lots'];
        $missing = array_values(array_filter($required, static fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $report = $this->evaluate([], [], [], [], count($missing));
        } else {
            $canonical = DB::table('ai_credit_provider_usages')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until)
                ->orderBy('id')->cursor();
            $legacy = DB::table('ai_usage_records')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until)
                ->orderBy('id')->cursor();
            $settlements = DB::table('ai_credit_lots')->whereIn('source', ['purchase', 'subscription'])->whereNotNull('commercial_order_id')
                ->select('commercial_order_id')->selectRaw('MIN(created_at) AS settled_at')->groupBy('commercial_order_id');
            $hasAllocation = Schema::hasColumn('commercial_orders', 'assistant_revenue_allocation');
            $payments = DB::table('commercial_payments as p')->join('commercial_orders as o', 'o.id', '=', 'p.commercial_order_id')
                ->leftJoinSub($settlements, 's', 's.commercial_order_id', '=', 'o.id')
                ->where('p.provider_status', 'succeeded')->whereIn('o.status', ['paid', 'refunded'])
                ->where(function ($dates) use ($from, $until): void {
                    $dates->where(function ($known) use ($from, $until): void {
                        $known->whereRaw('COALESCE(s.settled_at, p.terminal_at) >= ?', [$from])
                            ->whereRaw('COALESCE(s.settled_at, p.terminal_at) < ?', [$until]);
                    })->orWhere(function ($unknown) use ($until): void {
                        $unknown->whereNull('s.settled_at')->whereNull('p.terminal_at')->where('p.created_at', '<', $until);
                    });
                })
                ->where(function ($orders) use ($hasAllocation): void {
                    $orders->where('o.kind', 'ai_credits')->orWhereExists(function ($lots): void {
                        $lots->selectRaw('1')->from('ai_credit_lots as l')->whereColumn('l.commercial_order_id', 'o.id')->where('l.source', 'subscription');
                    });
                    if ($hasAllocation) { $orders->orWhereNotNull('o.assistant_revenue_allocation'); }
                })
                ->select(['p.id', 'p.provider', 'p.provider_payment_id', 'p.provider_status', 'p.amount_minor', 'p.currency', 'p.refunded_amount_minor', 'p.reconciliation_required',
                    'o.kind', 'o.status as order_status', 'o.amount_minor as order_amount_minor', 'o.currency as order_currency',
                    'o.period_start_at as order_period_start_at', 'o.period_end_at as order_period_end_at'])
                ->selectRaw('COALESCE(s.settled_at, p.terminal_at) AS settled_at')
                ->selectRaw($hasAllocation ? 'o.assistant_revenue_allocation' : 'NULL AS assistant_revenue_allocation')
                ->orderBy('p.id')->get()->map(static fn ($row): array => (array) $row)->all();
            $refunds = DB::table('commercial_refunds')->whereIn('commercial_payment_id', array_column($payments, 'id'))
                ->where('provider_status', 'succeeded')->orderBy('id')->cursor();
            $report = $this->evaluate($canonical, $legacy, $payments, $refunds);
        }

        return $report + ['period' => ['from' => $from->toAtomString(), 'until' => $until->toAtomString()],
            'generated_at' => CarbonImmutable::now()->toAtomString(),
            'revenue_basis' => 'settled_payment_cohort_net_of_current_refunds',
            'subscription_attribution_basis' => 'declared_internal_allocation',
            'subscription_refund_basis' => 'proportional_confirmed_payment_refund',
            'automatic_paid_approval' => false];
    }

    public function evaluate(iterable $canonical, iterable $legacy, iterable $payments, iterable $refunds, int $missingSources = 0): array
    {
        $cost = 0;
        $unknown = 0;
        $unknownAllocation = 0;
        $count = 0;
        $duplicates = 0;
        $excluded = 0;
        $calls = [];
        foreach ([$canonical, $legacy] as $journal => $rows) {
            foreach ($rows as $value) {
                $row = (array) $value;
                $operation = (string) ($row['operation'] ?? '');
                if (str_starts_with($operation, 'estimate_generation')) { $excluded++; continue; }
                $metadata = $this->metadata($row['metadata'] ?? []);
                $key = $this->callKey($row, $metadata);
                if ($key !== null && isset($calls[$key])) { $duplicates++; continue; }
                if ($journal === 1 && $operation === 'assistant_chat' && $key === null) { $unknown++; continue; }
                if ($key !== null) { $calls[$key] = true; }
                $count++;
                $available = ($metadata['cost_available'] ?? null) !== false
                    && ($metadata['provider_usage_available'] ?? null) !== false
                    && ($metadata['cost_is_estimate'] ?? false) !== true
                    && ($row['currency'] ?? 'RUB') === 'RUB';
                $amount = $journal === 0 ? $this->integer($row['cost_micro_rub'] ?? null) : $this->rubToMicro($row['total_cost_rub'] ?? null);
                if (! $available || $amount === null || ($journal === 0 && $amount === 0 && ($metadata['cost_available'] ?? null) !== true && ! $this->hasTokens($metadata))) {
                    $unknown++;
                    continue;
                }
                $cost = $this->add($cost, $amount);
            }
        }
        $refundTotals = [];
        $refundKeys = [];
        foreach ($refunds as $value) {
            $row = (array) $value;
            if (($row['provider_status'] ?? null) !== 'succeeded') { continue; }
            $id = (string) ($row['provider_refund_id'] ?? '');
            if ($id !== '') { $id = ($row['provider'] ?? 'unknown').':'.$id; }
            if ($id === '' || isset($refundKeys[$id])) { if ($id === '') { $unknownAllocation++; } continue; }
            $refundKeys[$id] = true;
            $payment = (string) ($row['commercial_payment_id'] ?? '');
            $amount = $this->integer($row['amount_minor'] ?? null);
            if ($amount === null || ($row['currency'] ?? null) !== 'RUB' || ($row['reconciliation_required'] ?? false)) {
                $unknownAllocation++;
                continue;
            }
            $refundTotals[$payment] = $this->add($refundTotals[$payment] ?? 0, $amount);
        }
        $gross = 0;
        $refunded = 0;
        $directRevenue = 0;
        $attributedRevenue = 0;
        $totalCash = 0;
        $allocatedPayments = 0;
        $excludedZeroAllocation = 0;
        $settled = 0;
        $paymentKeys = [];
        foreach ($payments as $value) {
            $row = (array) $value;
            if (($row['provider_status'] ?? null) !== 'succeeded' || ! in_array($row['order_status'] ?? null, ['paid', 'refunded'], true)) { continue; }
            $key = (string) ($row['provider_payment_id'] ?? '');
            if ($key !== '') { $key = ($row['provider'] ?? 'unknown').':'.$key; }
            if ($key === '' || isset($paymentKeys[$key])) { if ($key === '') { $unknownAllocation++; } continue; }
            $paymentKeys[$key] = true;
            $amount = $this->integer($row['amount_minor'] ?? null);
            $cumulative = $this->integer($row['refunded_amount_minor'] ?? null);
            $refund = max($cumulative ?? 0, $refundTotals[(string) ($row['id'] ?? '')] ?? 0);
            if ($amount === null || $cumulative === null || $amount !== $this->integer($row['order_amount_minor'] ?? null)
                || ($row['currency'] ?? null) !== 'RUB' || ($row['order_currency'] ?? null) !== 'RUB'
                || ($row['reconciliation_required'] ?? false) || $refund > $amount) { $unknownAllocation++; continue; }
            $snapshot = $this->metadata($row['assistant_revenue_allocation'] ?? null);
            if (array_key_exists('settled_at', $row) && $row['settled_at'] === null) {
                if (($row['kind'] ?? null) !== 'ai_credits' && ($snapshot['mode'] ?? null) === ($row['kind'] ?? null)
                    && $this->allocationPeriodMatches($snapshot, $row) && AssistantRevenueAllocation::verify($snapshot, $amount, $amount) === 0) {
                    $excludedZeroAllocation++;
                } else { $unknownAllocation++; }
                continue;
            }
            $totalCash = $this->add($totalCash, $amount - $refund);
            if (($row['kind'] ?? null) === 'ai_credits') {
                $gross = $this->add($gross, $amount);
                $refunded = $this->add($refunded, $refund);
                $directRevenue = $this->add($directRevenue, $amount - $refund);
            } else {
                if ($snapshot === [] || ($snapshot['mode'] ?? null) !== ($row['kind'] ?? null) || ! $this->allocationPeriodMatches($snapshot, $row)) { $unknownAllocation++; continue; }
                $allocation = AssistantRevenueAllocation::verify($snapshot, $amount, $amount);
                $net = AssistantRevenueAllocation::netAfterRefund($snapshot, $amount, $amount, $refund);
                if ($allocation === null || $net === null) { $unknownAllocation++; continue; }
                $gross = $this->add($gross, $allocation);
                $refunded = $this->add($refunded, $allocation - $net);
                $attributedRevenue = $this->add($attributedRevenue, $net);
                $allocatedPayments++;
            }
            $settled++;
        }
        $revenue = $gross - $refunded;
        $denominator = $revenue > 0 ? $this->multiply($revenue, 10000) : 0;
        $ratio = $denominator > 0 ? $cost / $denominator : null;
        $state = $unknown > 0 || $unknownAllocation > 0 || $missingSources > 0 ? 'coverage_incomplete'
            : ($revenue === 0 ? 'awaiting_actual_revenue' : ($cost > intdiv($this->multiply($denominator, 3), 10) ? 'exceeds_30_percent' : 'within_30_percent'));

        return ['schema_version' => 1, 'state' => $state, 'external_cost_micro_rub' => $cost,
            'assistant_revenue_minor' => $revenue, 'gross_assistant_revenue_minor' => $gross, 'assistant_refunds_minor' => $refunded,
            'direct_assistant_cash_revenue_minor' => $directRevenue, 'internally_attributed_assistant_revenue_minor' => $attributedRevenue,
            'total_confirmed_cohort_cash_minor' => $totalCash, 'internally_allocated_payment_count' => $allocatedPayments,
            'excluded_zero_ai_allocation_count' => $excludedZeroAllocation,
            'external_cost_revenue_ratio' => $ratio, 'unknown_cost_count' => $unknown, 'unknown_allocation_count' => $unknownAllocation,
            'provider_call_count' => $count, 'deduplicated_usage_count' => $duplicates, 'excluded_estimate_generation_count' => $excluded,
            'settled_assistant_payment_count' => $settled, 'missing_source_count' => max(0, $missingSources)];
    }

    private function metadata(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function callKey(array $row, array $metadata): ?string
    {
        $id = $metadata['provider_call_id'] ?? $row['usage_key'] ?? $metadata['usage_key'] ?? $metadata['credit_usage_key'] ?? null;
        return is_string($id) && $id !== '' ? hash('sha256', implode('|', [(string) ($row['organization_id'] ?? 0), (string) ($row['provider'] ?? ''), (string) ($row['model'] ?? ''), (string) ($row['operation'] ?? ''), $id])) : null;
    }

    private function allocationPeriodMatches(array $snapshot, array $row): bool
    {
        try {
            foreach (['period_start_at', 'period_end_at'] as $field) {
                if (array_key_exists('order_'.$field, $row) && (! is_string($row['order_'.$field])
                    || ! is_string($snapshot[$field] ?? null) || ! CarbonImmutable::parse($row['order_'.$field])->equalTo(CarbonImmutable::parse($snapshot[$field])))) { return false; }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTokens(array $metadata): bool
    {
        $usage = is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
        return (int) ($metadata['input_tokens'] ?? $usage['prompt_tokens'] ?? 0) > 0 || (int) ($metadata['output_tokens'] ?? $usage['completion_tokens'] ?? 0) > 0;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) { return $value >= 0 ? $value : null; }
        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1 || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) { return null; }
        return (int) $value;
    }

    private function rubToMicro(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^([0-9]+)(?:\.([0-9]{1,6}))?$/D', $value, $matches) !== 1) { return null; }
        $whole = $this->integer($matches[1]);
        if ($whole === null || $whole > intdiv(PHP_INT_MAX, 1000000)) { return null; }
        return $this->add($whole * 1000000, (int) str_pad($matches[2] ?? '', 6, '0'));
    }

    private function add(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) { throw new InvalidArgumentException('economics_integer_overflow'); }
        return $left + $right;
    }

    private function multiply(int $value, int $factor): int
    {
        if ($value > intdiv(PHP_INT_MAX, $factor)) { throw new InvalidArgumentException('economics_integer_overflow'); }
        return $value * $factor;
    }
}
