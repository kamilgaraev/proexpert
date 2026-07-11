<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

final class OperationalUsageSummary
{
    /** @param array<string, mixed> $aggregate @return array<string, mixed> */
    public static function fromAggregate(array $aggregate): array
    {
        $attempts = self::number($aggregate, 'attempts');
        $knownAttempts = self::number($aggregate, 'known_cost_attempts');
        $unknownAttempts = self::number($aggregate, 'unknown_cost_attempts');
        $currencyCount = self::number($aggregate, 'currency_count');
        $minCurrency = self::string($aggregate['min_currency'] ?? null);
        $maxCurrency = self::string($aggregate['max_currency'] ?? null);
        $mixedCurrency = $currencyCount > 1 || ($minCurrency !== null && $maxCurrency !== null && $minCurrency !== $maxCurrency);
        $currency = ! $mixedCurrency && $currencyCount === 1 && $minCurrency === $maxCurrency ? $minCurrency : null;
        $subtotal = self::decimal($aggregate['known_cost_subtotal'] ?? null);
        $costKnown = $attempts > 0
            && $knownAttempts === $attempts
            && $unknownAttempts === 0
            && $currency !== null
            && $subtotal !== null;
        $input = self::number($aggregate, 'input_tokens');
        $output = self::number($aggregate, 'output_tokens');
        $reasoning = self::number($aggregate, 'reasoning_tokens');

        return [
            'attempts' => $attempts,
            'tokens' => $input + $output + $reasoning,
            'input_tokens' => $input,
            'cached_input_tokens' => self::number($aggregate, 'cached_input_tokens'),
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'cost_amount' => $costKnown ? $subtotal : null,
            'cost_known' => $costKnown,
            'known_cost_attempts' => $knownAttempts,
            'unknown_cost_attempts' => $unknownAttempts,
            'known_cost_subtotal' => $mixedCurrency ? null : $subtotal,
            'currency' => $currency,
            'mixed_currency' => $mixedCurrency,
            'failed_attempts' => self::number($aggregate, 'failed'),
        ];
    }

    /** @param array<string, mixed> $source */
    private static function number(array $source, string $key): int
    {
        return is_numeric($source[$key] ?? null) ? max(0, (int) $source[$key]) : 0;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function decimal(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $value) === 1 ? $value : null;
    }
}
