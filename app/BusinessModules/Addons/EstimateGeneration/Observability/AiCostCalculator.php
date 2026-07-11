<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final class AiCostCalculator
{
    private const SCALE = 16;

    /** @param array<string, mixed> $priceSnapshot */
    public function calculate(
        int $inputTokens,
        int $cachedInputTokens,
        int $outputTokens,
        int $reasoningTokens,
        int $imageCount,
        int $pageCount,
        array $priceSnapshot,
    ): AiCost {
        $currency = $priceSnapshot['currency'] ?? null;
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return new AiCost(null, null, 'unavailable');
        }

        $required = ['input_per_million', 'cached_input_per_million', 'output_per_million'];
        foreach ($required as $key) {
            if (! $this->decimal($priceSnapshot[$key] ?? null)) {
                return new AiCost(null, null, 'unavailable');
            }
        }

        foreach ([$inputTokens, $cachedInputTokens, $outputTokens, $reasoningTokens, $imageCount, $pageCount] as $counter) {
            if ($counter < 0) {
                throw new InvalidArgumentException('Usage counters must be nonnegative.');
            }
        }
        if ($cachedInputTokens > $inputTokens) {
            throw new InvalidArgumentException('Usage counter subsets exceed totals.');
        }

        $uncached = max(0, $inputTokens - $cachedInputTokens);
        $amount = $this->tokenCost((string) $uncached, (string) $priceSnapshot['input_per_million']);
        $amount = bcadd($amount, $this->tokenCost((string) min($inputTokens, $cachedInputTokens), (string) $priceSnapshot['cached_input_per_million']), self::SCALE);
        $reasoningMode = (string) ($priceSnapshot['reasoning_mode'] ?? 'excluded_from_output');
        if (! in_array($reasoningMode, ['included_in_output', 'excluded_from_output'], true)) {
            throw new InvalidArgumentException('Invalid reasoning pricing mode.');
        }
        if ($reasoningMode === 'included_in_output' && $reasoningTokens > $outputTokens) {
            throw new InvalidArgumentException('Included reasoning cannot exceed output tokens.');
        }
        $billableOutput = $reasoningMode === 'included_in_output' ? $outputTokens - $reasoningTokens : $outputTokens;
        $amount = bcadd($amount, $this->tokenCost((string) $billableOutput, (string) $priceSnapshot['output_per_million']), self::SCALE);

        if ($reasoningTokens > 0) {
            if (! $this->decimal($priceSnapshot['reasoning_per_million'] ?? null)) {
                return new AiCost(null, null, 'unavailable');
            }
            $amount = bcadd($amount, $this->tokenCost((string) $reasoningTokens, (string) $priceSnapshot['reasoning_per_million']), self::SCALE);
        }

        foreach ([['image_unit', $imageCount], ['page_unit', $pageCount]] as [$key, $count]) {
            if ($count > 0) {
                if (! $this->decimal($priceSnapshot[$key] ?? null)) {
                    return new AiCost(null, null, 'unavailable');
                }
                $amount = bcadd($amount, bcmul((string) $count, (string) $priceSnapshot[$key], self::SCALE), self::SCALE);
            }
        }

        $rounded = $this->roundHalfUp($amount);
        if (strlen(strtok($rounded, '.')) > 10) {
            throw new InvalidArgumentException('Calculated cost exceeds decimal(18,8).');
        }

        return new AiCost($rounded, $currency, 'available');
    }

    private function tokenCost(string $tokens, string $rate): string
    {
        return bcdiv(bcmul($tokens, $rate, self::SCALE), '1000000', self::SCALE);
    }

    private function roundHalfUp(string $amount): string
    {
        return bcadd($amount, '0.000000005', 8);
    }

    private function decimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1;
    }
}
