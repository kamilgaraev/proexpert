<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Services\Modules\PackageCatalogService;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

final class AssistantRevenueAllocation
{
    public function __construct(private readonly PackageCatalogService $catalog, private readonly ?string $manifestPath = null) {}

    public function snapshot(array $quote, CarbonInterface $calculatedAt, ?CarbonInterface $currentStart, ?CarbonInterface $currentEnd, int $orderAmountMinor, bool $renewal = false, ?CarbonInterface $renewalStart = null, ?CarbonInterface $renewalEnd = null): array
    {
        try {
            $manifestPath = $this->manifestPath ?? dirname(__DIR__, 3).'/config/ModuleList/addons/ai-assistant.json';
            if (!is_file($manifestPath) || !is_readable($manifestPath)) { return $this->unknown('missing_manifest'); }
            $contents = file_get_contents($manifestPath);
            if (!is_string($contents)) { return $this->unknown('missing_manifest'); }
            $manifest = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
            if (($manifest['slug'] ?? null) !== 'ai-assistant' || ($manifest['pricing']['base_price'] ?? null) !== 3990 || ($manifest['pricing']['currency'] ?? null) !== 'RUB' || ($manifest['pricing']['duration_days'] ?? null) !== 30) { return $this->unknown('unsupported_manifest'); }
            if (($quote['currency'] ?? null) !== 'RUB' || ($quote['billing_period_days'] ?? null) !== 30 || !in_array($quote['offer_type'] ?? null, ['packages', 'full_suite'], true)) { return $this->unknown('unsupported_quote'); }
            foreach (['monthly_total_minor', 'amount_due_now_minor', 'quote_version'] as $field) {
                if (!is_int($quote[$field] ?? null) || $quote[$field] < 0) { return $this->unknown('invalid_quote_amount'); }
            }
            $prices = [];
            $modules = [];
            foreach ($this->catalog->allPackages() as $package) {
                $slug = $package['slug'] ?? null;
                $price = $package['tiers']['standard']['price'] ?? null;
                if (!is_string($slug) || !is_int($price) || $price < 0 || $price > intdiv(PHP_INT_MAX, 100)) { return $this->unknown('invalid_catalog'); }
                $prices[$slug] = $price * 100;
                $modules[$slug] = $this->catalog->tierModules($slug, 'standard', true);
                sort($modules[$slug]);
            }
            ksort($prices);
            ksort($modules);
            $target = $this->slugs($quote['target_package_slugs'] ?? null, $prices);
            $current = $this->slugs($quote['current_package_slugs'] ?? null, $prices);
            if ($target === null || $current === null) { return $this->unknown('unknown_package'); }
            $total = BigInteger::zero();
            foreach ($target as $slug) { $total = $total->plus($prices[$slug]); }
            $catalogTotal = $total->toInt();
            $monthly = $quote['monthly_total_minor'];
            if ($catalogTotal <= 0 || ($quote['offer_type'] === 'packages' && $monthly !== $catalogTotal)) { return $this->unknown('inconsistent_catalog_total'); }
            if (($currentStart === null) !== ($currentEnd === null) || !isset($quote['period_start_at'], $quote['period_end_at'])) { return $this->unknown('missing_period_basis'); }
            $now = CarbonImmutable::instance($calculatedAt);
            $quoteStart = CarbonImmutable::parse($quote['period_start_at']);
            $quoteEnd = CarbonImmutable::parse($quote['period_end_at']);
            if ((int) $quoteStart->diffInMicroseconds($quoteEnd) !== 30 * 86400000000) { return $this->unknown('invalid_quote_period'); }
            $upgrade = !$renewal && $currentStart !== null && $currentEnd !== null && $now->greaterThanOrEqualTo($currentStart) && $now->lessThan($currentEnd);
            $denominator = 30 * 86400;
            $numerator = $denominator;
            if ($upgrade) {
                if (!$quoteStart->equalTo($currentStart) || !$quoteEnd->equalTo($currentEnd)) { return $this->unknown('inconsistent_upgrade_period'); }
                $numerator = (int) $now->diffInSeconds($quoteEnd);
            }
            if (!$renewal && !$upgrade && !$quoteStart->equalTo($now)) { return $this->unknown('inconsistent_initial_period'); }
            $chargeBase = BigInteger::of($monthly);
            if ($upgrade) {
                if ($quote['offer_type'] === 'full_suite') {
                    $currentTotal = BigInteger::zero();
                    foreach ($current as $slug) { $currentTotal = $currentTotal->plus($prices[$slug]); }
                    $chargeBase = BigInteger::max(0, $chargeBase->minus($currentTotal));
                } else {
                    $chargeBase = BigInteger::zero();
                    foreach (array_diff($target, $current) as $slug) { $chargeBase = $chargeBase->plus($prices[$slug]); }
                }
            }
            if (!$renewal) {
                $expectedDue = $chargeBase->multipliedBy($numerator)->plus(intdiv($denominator, 2))->dividedBy($denominator, RoundingMode::Down)->toInt();
                if ($expectedDue !== $quote['amount_due_now_minor']) { return $this->unknown('inconsistent_actual_quote'); }
            }
            $payable = $renewal ? $monthly : $quote['amount_due_now_minor'];
            if ($orderAmountMinor <= 0 || $payable > $orderAmountMinor) { return $this->unknown('invalid_order_amount'); }
            $periodStart = $renewal ? $renewalStart : $quoteStart;
            $periodEnd = $renewal ? $renewalEnd : $quoteEnd;
            if ($periodStart === null || $periodEnd === null || (int) $periodStart->diffInMicroseconds($periodEnd) !== 30 * 86400000000) { return $this->unknown('invalid_order_period'); }
            $hasAi = static function (array $slugs) use ($modules): bool {
                foreach ($slugs as $slug) { if (in_array('ai-assistant', $modules[$slug], true)) { return true; } }
                return false;
            };
            $input = ['quote' => $quote, 'prices_minor' => $prices, 'package_modules' => $modules, 'calculated_at' => $now->toAtomString(), 'current_start_at' => $currentStart?->toAtomString(), 'current_end_at' => $currentEnd?->toAtomString(), 'renewal' => $renewal, 'order_amount_minor' => $orderAmountMinor];
            $snapshot = ['policy_version' => 1, 'status' => 'allocated', 'basis' => 'declared_internal_allocation', 'currency' => 'RUB', 'mode' => $renewal ? 'renewal' : 'purchase', 'module_source' => 'config/ModuleList/addons/ai-assistant.json', 'source_normalization' => 'utf8_lf', 'module_source_sha256' => hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents)), 'quote_inputs_sha256' => self::digest($input), 'quote_version' => $quote['quote_version'], 'period_start_at' => $periodStart->toAtomString(), 'period_end_at' => $periodEnd->toAtomString(), 'standalone_amount_minor' => 399000, 'monthly_total_minor' => $monthly, 'catalog_total_minor' => $catalogTotal, 'recurring_payable_minor' => $payable, 'order_amount_minor' => $orderAmountMinor, 'period_ratio_numerator' => $numerator, 'period_ratio_denominator' => $denominator, 'target_ai' => $hasAi($target), 'current_ai' => $hasAi($current), 'is_upgrade' => $upgrade];
            $snapshot['amount_minor'] = self::calculate($snapshot);
            $snapshot['policy_sha256'] = self::policyHash($snapshot['module_source_sha256']);
            $snapshot['snapshot_sha256'] = self::digest($snapshot);
            return $snapshot;
        } catch (Throwable) {
            return $this->unknown('unsupported_server_basis');
        }
    }

    public static function verify(array $snapshot, int $paymentAmountMinor, int $orderAmountMinor): ?int
    {
        try {
            if (($snapshot['policy_version'] ?? null) !== 1 || ($snapshot['status'] ?? null) !== 'allocated' || ($snapshot['basis'] ?? null) !== 'declared_internal_allocation' || ($snapshot['currency'] ?? null) !== 'RUB' || !in_array($snapshot['mode'] ?? null, ['purchase', 'renewal'], true) || $paymentAmountMinor <= 0 || $paymentAmountMinor !== $orderAmountMinor || ($snapshot['order_amount_minor'] ?? null) !== $orderAmountMinor) { return null; }
            foreach (['standalone_amount_minor', 'monthly_total_minor', 'catalog_total_minor', 'recurring_payable_minor', 'period_ratio_numerator', 'period_ratio_denominator', 'amount_minor', 'quote_version'] as $key) {
                if (!is_int($snapshot[$key] ?? null) || $snapshot[$key] < 0) { return null; }
            }
            foreach (['target_ai', 'current_ai', 'is_upgrade'] as $key) { if (!is_bool($snapshot[$key] ?? null)) { return null; } }
            foreach (['module_source_sha256', 'quote_inputs_sha256', 'policy_sha256', 'snapshot_sha256'] as $key) { if (!is_string($snapshot[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $snapshot[$key]) !== 1) { return null; } }
            if ($snapshot['standalone_amount_minor'] !== 399000 || $snapshot['catalog_total_minor'] <= 0 || $snapshot['period_ratio_denominator'] !== 30 * 86400 || $snapshot['period_ratio_numerator'] > $snapshot['period_ratio_denominator'] || $snapshot['recurring_payable_minor'] > $orderAmountMinor || ($snapshot['source_normalization'] ?? null) !== 'utf8_lf' || ($snapshot['module_source'] ?? null) !== 'config/ModuleList/addons/ai-assistant.json') { return null; }
            if (!is_string($snapshot['period_start_at'] ?? null) || !is_string($snapshot['period_end_at'] ?? null) || $snapshot['period_start_at'] === '' || $snapshot['period_end_at'] === '') { return null; }
            $start = CarbonImmutable::parse($snapshot['period_start_at']);
            $end = CarbonImmutable::parse($snapshot['period_end_at']);
            if ((int) $start->diffInMicroseconds($end) !== 30 * 86400000000 || ($snapshot['mode'] === 'renewal' && ($snapshot['is_upgrade'] || $snapshot['period_ratio_numerator'] !== $snapshot['period_ratio_denominator'] || $snapshot['recurring_payable_minor'] !== $snapshot['monthly_total_minor']))) { return null; }
            $claimed = $snapshot['snapshot_sha256'];
            unset($snapshot['snapshot_sha256']);
            if (!hash_equals($claimed, self::digest($snapshot)) || !hash_equals($snapshot['policy_sha256'], self::policyHash($snapshot['module_source_sha256'])) || $snapshot['amount_minor'] !== self::calculate($snapshot)) { return null; }
            return $snapshot['amount_minor'];
        } catch (Throwable) { return null; }
    }

    public static function netAfterRefund(array $snapshot, int $paymentAmountMinor, int $orderAmountMinor, int $cumulativeRefundMinor): ?int
    {
        $gross = self::verify($snapshot, $paymentAmountMinor, $orderAmountMinor);
        if ($gross === null || $cumulativeRefundMinor < 0 || $cumulativeRefundMinor > $paymentAmountMinor) { return null; }
        $refund = BigInteger::of($gross)->multipliedBy($cumulativeRefundMinor)->dividedBy($paymentAmountMinor, RoundingMode::Down)->toInt();
        return min($gross - $refund, $paymentAmountMinor - $cumulativeRefundMinor);
    }

    private function slugs(mixed $value, array $prices): ?array
    {
        if (!is_array($value) || !array_is_list($value)) { return null; }
        foreach ($value as $slug) { if (!is_string($slug) || !array_key_exists($slug, $prices)) { return null; } }
        if (count(array_unique($value)) !== count($value)) { return null; }
        sort($value);
        return $value;
    }

    private function unknown(string $reason): array
    {
        return ['policy_version' => 1, 'status' => 'unknown', 'basis' => 'declared_internal_allocation', 'reason' => $reason];
    }

    private static function calculate(array $snapshot): int
    {
        if (!$snapshot['target_ai'] || ($snapshot['is_upgrade'] && $snapshot['current_ai'])) { return 0; }
        return min($snapshot['recurring_payable_minor'], BigInteger::of(399000)->multipliedBy(min($snapshot['monthly_total_minor'], $snapshot['catalog_total_minor']))->multipliedBy($snapshot['period_ratio_numerator'])->dividedBy(BigInteger::of($snapshot['catalog_total_minor'])->multipliedBy($snapshot['period_ratio_denominator']), RoundingMode::Down)->toInt());
    }

    private static function policyHash(string $manifestHash): string
    {
        return self::digest(['version' => 1, 'basis' => 'declared_internal_allocation', 'formula' => 'floor(B*min(T,C)*R/(C*D));cap_recurring_payable;unchanged_ai_upgrade_zero', 'standalone_minor' => 399000, 'duration_days' => 30, 'module_source_sha256' => $manifestHash]);
    }

    private static function digest(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if ($item instanceof CarbonInterface) { return $item->toAtomString(); }
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item); }
            return array_map($normalize, $item);
        };
        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
