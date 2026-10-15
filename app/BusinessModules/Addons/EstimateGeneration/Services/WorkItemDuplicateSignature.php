<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;

final readonly class WorkItemDuplicateSignature
{
    private function __construct(public string $value) {}

    /** @param array<string, mixed> $workItem */
    public static function fromWorkItem(array $workItem): ?self
    {
        $name = self::normalize((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''));
        $unit = self::normalize((string) ($workItem['unit'] ?? ''));
        $quantity = self::quantity($workItem['quantity'] ?? null);
        $physicalIdentity = WorkItemPhysicalIdentity::fromWorkItem($workItem);

        if ($name === '' || $unit === '' || $quantity === null || $physicalIdentity === null) {
            return null;
        }

        $normativeIdentity = self::normalize((string) (
            $workItem['normative_rate_code']
            ?? $workItem['normative_search_key']
            ?? ''
        ));

        return new self(hash('sha256', CanonicalPipelineJson::encode([
            $name,
            $unit,
            $quantity,
            $normativeIdentity,
            $physicalIdentity,
        ])));
    }

    private static function quantity(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || (is_float($value) && ! is_finite($value)) || strlen((string) $value) > 128
            || (is_string($value) && preg_match('/^\+?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value) !== 1)) {
            return null;
        }

        try {
            $decimal = is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : (string) $value;
            $quantity = BigDecimal::of($decimal);

            return $quantity->isPositive() ? (string) $quantity->strippedOfTrailingZeros() : null;
        } catch (\Brick\Math\Exception\MathException) {
            return null;
        }
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
