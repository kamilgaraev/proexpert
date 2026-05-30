<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns;

use BackedEnum;
use DateTimeInterface;

trait FormatsRagSourceContent
{
    protected function lines(array $lines): string
    {
        return implode("\n", array_filter(
            $lines,
            static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')
        ));
    }

    protected function scalarValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    protected function stringValue(mixed $value): string
    {
        $value = $this->scalarValue($value);

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function numberValue(mixed $value, int $precision = 3): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.') : '';
    }

    protected function moneyValue(mixed $amount, mixed $currency = null): string
    {
        if (! is_numeric($amount)) {
            return '';
        }

        $suffix = $this->stringValue($currency);

        return trim(number_format((float) $amount, 2, '.', ' ').' '.$suffix);
    }

    protected function dateValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function dateTimeValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function boolValue(mixed $value): string
    {
        return (bool) $value ? 'yes' : 'no';
    }

    protected function arrayValue(mixed $value, int $limit = 6): string
    {
        if (! is_array($value)) {
            return '';
        }

        $items = [];
        foreach ($value as $key => $item) {
            if (count($items) >= $limit) {
                break;
            }

            $string = $this->stringValue($item);
            if ($string === '' && is_scalar($key)) {
                $string = $this->stringValue($key);
            }

            if ($string !== '') {
                $items[] = $string;
            }
        }

        return implode(', ', $items);
    }
}
