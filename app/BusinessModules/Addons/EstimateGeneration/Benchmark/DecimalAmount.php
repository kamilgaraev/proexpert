<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;

final class DecimalAmount
{
    private const SCALE = 9;

    private string $minor = '0';

    public function add(string $amount): void
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,9}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('decimal_amount_invalid');
        }
        $fraction = str_pad($matches[2] ?? '', self::SCALE, '0');
        $minor = ltrim($matches[1].$fraction, '0');
        $this->minor = $this->addIntegerStrings($this->minor, $minor === '' ? '0' : $minor);
    }

    public function value(): string
    {
        $digits = str_pad($this->minor, self::SCALE + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -self::SCALE);
        $fraction = rtrim(substr($digits, -self::SCALE), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    private function addIntegerStrings(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += ord($left[$leftIndex--]) - 48;
            }
            if ($rightIndex >= 0) {
                $sum += ord($right[$rightIndex--]) - 48;
            }
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }
}
