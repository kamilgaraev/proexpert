<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\OperationalUsageSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationalUsageSummaryTest extends TestCase
{
    #[Test]
    #[DataProvider('costCases')]
    public function cost_is_known_only_when_every_attempt_is_priced_in_one_currency(array $aggregate, array $expected): void
    {
        self::assertSame($expected, OperationalUsageSummary::fromAggregate($aggregate));
    }

    public static function costCases(): array
    {
        return [
            'empty' => [self::aggregate(), self::expected()],
            'known zero' => [self::aggregate(1, 1, 0, '0.00000000', 1, 'RUB', 'RUB'), self::expected(1, '0.00000000', true, 0, 1, '0.00000000', 'RUB')],
            'mixed known unknown' => [self::aggregate(2, 1, 1, '0.12500000', 1, 'RUB', 'RUB'), self::expected(2, null, false, 1, 1, '0.12500000', 'RUB')],
            'all known' => [self::aggregate(3, 3, 0, '1.25000000', 1, 'RUB', 'RUB'), self::expected(3, '1.25000000', true, 0, 3, '1.25000000', 'RUB')],
            'mixed currency' => [self::aggregate(2, 2, 0, '2.00000000', 2, 'RUB', 'USD'), self::expected(2, null, false, 0, 2, null, null, true)],
        ];
    }

    #[Test]
    public function cached_tokens_are_a_subset_and_never_double_counted(): void
    {
        $summary = OperationalUsageSummary::fromAggregate([
            ...self::aggregate(),
            'input_tokens' => 100,
            'cached_input_tokens' => 40,
            'output_tokens' => 20,
            'reasoning_tokens' => 5,
        ]);

        self::assertSame(125, $summary['tokens']);
        self::assertSame(100, $summary['input_tokens']);
        self::assertSame(40, $summary['cached_input_tokens']);
    }

    private static function aggregate(
        int $attempts = 0,
        int $known = 0,
        int $unknown = 0,
        ?string $subtotal = null,
        int $currencyCount = 0,
        ?string $minCurrency = null,
        ?string $maxCurrency = null,
    ): array {
        return [
            'attempts' => $attempts,
            'known_cost_attempts' => $known,
            'unknown_cost_attempts' => $unknown,
            'known_cost_subtotal' => $subtotal,
            'currency_count' => $currencyCount,
            'min_currency' => $minCurrency,
            'max_currency' => $maxCurrency,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'failed' => 0,
        ];
    }

    private static function expected(
        int $attempts = 0,
        ?string $cost = null,
        bool $known = false,
        int $unknown = 0,
        int $knownAttempts = 0,
        ?string $subtotal = null,
        ?string $currency = null,
        bool $mixedCurrency = false,
    ): array {
        return [
            'attempts' => $attempts,
            'tokens' => 0,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_amount' => $cost,
            'cost_known' => $known,
            'known_cost_attempts' => $knownAttempts,
            'unknown_cost_attempts' => $unknown,
            'known_cost_subtotal' => $subtotal,
            'currency' => $currency,
            'mixed_currency' => $mixedCurrency,
            'failed_attempts' => 0,
        ];
    }
}
