<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Throwable;

trait ReportDateHelper
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{date_from: string|null, date_to: string|null}
     */
    protected function extractPeriodFromArguments(array $arguments, string $defaultPeriod): array
    {
        $hasDateFromArgument = $this->hasDateArgument($arguments, 'date_from');
        $hasDateToArgument = $this->hasDateArgument($arguments, 'date_to');
        $dateFrom = $this->normalizeDateArgument($arguments['date_from'] ?? null);
        $dateTo = $this->normalizeDateArgument($arguments['date_to'] ?? null, true);

        if (($hasDateFromArgument && $dateFrom === null) || ($hasDateToArgument && $dateTo === null)) {
            return $this->extractPeriod((string) ($arguments['period'] ?? $defaultPeriod));
        }

        if ($dateFrom !== null || $dateTo !== null) {
            $from = $dateFrom !== null ? $this->carbonFromDateTimeString($dateFrom) : null;
            $to = $dateTo !== null ? $this->carbonFromDateTimeString($dateTo) : null;

            if (($dateFrom !== null && $from === null) || ($dateTo !== null && $to === null)) {
                return $this->extractPeriod((string) ($arguments['period'] ?? $defaultPeriod));
            }

            if ($from !== null && $to !== null && $from->greaterThan($to)) {
                return $this->extractPeriod((string) ($arguments['period'] ?? $defaultPeriod));
            }

            return [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ];
        }

        return $this->extractPeriod((string) ($arguments['period'] ?? $defaultPeriod));
    }

    /**
     * @return array{date_from: string|null, date_to: string|null}
     */
    protected function extractPeriod(string $query): array
    {
        $query = mb_strtolower($query);
        $now = Carbon::now();

        if (preg_match('/\b(?:с|от)\s+начала\s+(?:проекта|объекта|работ|строительства)\b/ui', $query)) {
            return [
                'date_from' => null,
                'date_to' => $now->copy()->endOfDay()->toDateTimeString(),
            ];
        }

        if (preg_match('/(последн|прошл).* месяц/ui', $query)) {
            return [
                'date_from' => $now->copy()->subMonth()->startOfMonth()->toDateTimeString(),
                'date_to' => $now->copy()->subMonth()->endOfMonth()->toDateTimeString(),
            ];
        }

        if (preg_match('/(этот|текущ|за).* месяц/ui', $query)) {
            return [
                'date_from' => $now->copy()->startOfMonth()->toDateTimeString(),
                'date_to' => $now->copy()->endOfMonth()->toDateTimeString(),
            ];
        }

        if (preg_match('/(квартал|кварт)/ui', $query)) {
            return [
                'date_from' => $now->copy()->startOfQuarter()->toDateTimeString(),
                'date_to' => $now->copy()->endOfQuarter()->toDateTimeString(),
            ];
        }

        if (preg_match('/(год|за год|в год)/ui', $query)) {
            return [
                'date_from' => $now->copy()->startOfYear()->toDateTimeString(),
                'date_to' => $now->copy()->endOfYear()->toDateTimeString(),
            ];
        }

        $months = [
            'январ' => 1,
            'феврал' => 2,
            'март' => 3,
            'апрел' => 4,
            'ма[йя]' => 5,
            'июн' => 6,
            'июл' => 7,
            'август' => 8,
            'сентябр' => 9,
            'октябр' => 10,
            'ноябр' => 11,
            'декабр' => 12,
        ];

        foreach ($months as $pattern => $monthNumber) {
            if (preg_match("/{$pattern}/ui", $query)) {
                $date = Carbon::create($now->year, $monthNumber, 1);

                return [
                    'date_from' => $date->copy()->startOfMonth()->toDateTimeString(),
                    'date_to' => $date->copy()->endOfMonth()->toDateTimeString(),
                ];
            }
        }

        if (preg_match('/(весь|всё|все).* (период|время)/ui', $query)) {
            return [
                'date_from' => null,
                'date_to' => null,
            ];
        }

        return [
            'date_from' => $now->copy()->startOfMonth()->toDateTimeString(),
            'date_to' => $now->copy()->endOfMonth()->toDateTimeString(),
        ];
    }

    private function hasDateArgument(array $arguments, string $key): bool
    {
        if (! array_key_exists($key, $arguments)) {
            return false;
        }

        $value = $arguments[$key];

        return $value !== null && $value !== '';
    }

    private function normalizeDateArgument(mixed $value, bool $endOfDay = false): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $date = Carbon::instance($value)->copy();

            return $endOfDay
                ? $date->endOfDay()->toDateTimeString()
                : $date->startOfDay()->toDateTimeString();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $endOfDay
            ? $date->endOfDay()->toDateTimeString()
            : $date->startOfDay()->toDateTimeString();
    }

    private function carbonFromDateTimeString(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d H:i:s', $value);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonInterface ? $date : null;
    }
}
