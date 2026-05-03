<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantResolvedPeriod;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidDateException;

final class AssistantPeriodResolver
{
    /**
     * @var array<string, int>
     */
    private const MONTHS = [
        'январь' => 1,
        'января' => 1,
        'февраль' => 2,
        'февраля' => 2,
        'март' => 3,
        'марта' => 3,
        'апрель' => 4,
        'апреля' => 4,
        'май' => 5,
        'мая' => 5,
        'июнь' => 6,
        'июня' => 6,
        'июль' => 7,
        'июля' => 7,
        'август' => 8,
        'августа' => 8,
        'сентябрь' => 9,
        'сентября' => 9,
        'октябрь' => 10,
        'октября' => 10,
        'ноябрь' => 11,
        'ноября' => 11,
        'декабрь' => 12,
        'декабря' => 12,
    ];

    /**
     * @var array<int, string>
     */
    private const MONTH_LABELS = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    public function __construct(
        private readonly ?CarbonImmutable $now = null
    ) {}

    /**
     * @param  array{date_from?: mixed, date_to?: mixed, label?: mixed, source_text?: mixed}|string|null  $input
     */
    public function resolve(string|array|null $input): ?AssistantResolvedPeriod
    {
        if (is_array($input)) {
            return $this->resolveArray($input);
        }

        $sourceText = trim((string) $input);
        if ($sourceText === '') {
            return null;
        }

        $normalized = $this->normalize($sourceText);
        $now = ($this->now ?? CarbonImmutable::now('Europe/Moscow'))->startOfDay();

        return $this->resolveExplicitDateRange($normalized, $sourceText)
            ?? $this->resolveRelativeMonth($normalized, $sourceText, $now)
            ?? $this->resolveRelativeYear($normalized, $sourceText, $now)
            ?? $this->resolveLastWeeks($normalized, $sourceText, $now)
            ?? $this->resolveWeeksAgo($normalized, $sourceText, $now)
            ?? $this->resolveNamedMonth($normalized, $sourceText, $now);
    }

    /**
     * @param  array{date_from?: mixed, date_to?: mixed, label?: mixed, source_text?: mixed}  $input
     */
    private function resolveArray(array $input): ?AssistantResolvedPeriod
    {
        $dateFrom = $this->stringValue($input['date_from'] ?? null);
        $dateTo = $this->stringValue($input['date_to'] ?? null);

        if ($dateFrom === '' || $dateTo === '') {
            return null;
        }

        $parsedFrom = $this->dateFromString($dateFrom);
        $parsedTo = $this->dateFromString($dateTo);

        if ($parsedFrom === null || $parsedTo === null || $parsedFrom->greaterThan($parsedTo)) {
            return null;
        }

        $inputLabel = $this->stringValue($input['label'] ?? null);
        $sourceText = $this->stringValue($input['source_text'] ?? null);

        $label = $inputLabel !== ''
            ? $inputLabel
            : "{$parsedFrom->toDateString()} - {$parsedTo->toDateString()}";

        return new AssistantResolvedPeriod(
            dateFrom: $parsedFrom->toDateString(),
            dateTo: $parsedTo->toDateString(),
            label: $label,
            sourceText: $sourceText
        );
    }

    private function resolveExplicitDateRange(string $normalized, string $sourceText): ?AssistantResolvedPeriod
    {
        if (preg_match('/\bс\s+(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\s+по\s+(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $dateFrom = $this->dateFromParts((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        $dateTo = $this->dateFromParts((int) $matches[6], (int) $matches[5], (int) $matches[4]);

        if ($dateFrom === null || $dateTo === null || $dateFrom->greaterThan($dateTo)) {
            return null;
        }

        return $this->period($dateFrom, $dateTo, 'Указанный период', $sourceText);
    }

    private function resolveRelativeMonth(string $normalized, string $sourceText, CarbonImmutable $now): ?AssistantResolvedPeriod
    {
        if (preg_match('/\bза\s+последн(?:ий|ие|юю)\s+месяц\b/u', $normalized) === 1) {
            return $this->period($now->subMonthNoOverflow(), $now, 'Последний месяц', $sourceText);
        }

        if (preg_match('/\bза\s+(?:этот|текущий)\s+месяц\b/u', $normalized) === 1) {
            return $this->period($now->startOfMonth(), $now, 'Текущий месяц', $sourceText);
        }

        if (preg_match('/\bза\s+прошл(?:ый|ом)\s+месяц\b/u', $normalized) === 1) {
            $previousMonth = $now->subMonthNoOverflow();

            return $this->period($previousMonth->startOfMonth(), $previousMonth->endOfMonth(), 'Прошлый месяц', $sourceText);
        }

        return null;
    }

    private function resolveRelativeYear(string $normalized, string $sourceText, CarbonImmutable $now): ?AssistantResolvedPeriod
    {
        if (preg_match('/\bза\s+(?:этот|текущий)\s+год\b/u', $normalized) === 1) {
            return $this->period($now->startOfYear(), $now, 'Текущий год', $sourceText);
        }

        if (preg_match('/\bза\s+прошл(?:ый|ом)\s+год\b/u', $normalized) === 1) {
            $previousYear = $now->subYear();

            return $this->period($previousYear->startOfYear(), $previousYear->endOfYear(), 'Прошлый год', $sourceText);
        }

        return null;
    }

    private function resolveLastWeeks(string $normalized, string $sourceText, CarbonImmutable $now): ?AssistantResolvedPeriod
    {
        if (preg_match('/\bза\s+(\d{1,2})\s+недел(?:ю|и|ь)\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $weeks = (int) $matches[1];
        if ($weeks < 1) {
            return null;
        }

        return $this->period($now->subWeeks($weeks), $now, "За {$weeks} недели", $sourceText);
    }

    private function resolveWeeksAgo(string $normalized, string $sourceText, CarbonImmutable $now): ?AssistantResolvedPeriod
    {
        if (preg_match('/\b(\d{1,2})\s+недел(?:ю|и|ь)\s+назад\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $weeks = (int) $matches[1];
        if ($weeks < 1) {
            return null;
        }

        $dateTo = $now->subWeeks($weeks)->addDay();

        return $this->period($dateTo->subWeek(), $dateTo, "{$weeks} недели назад", $sourceText);
    }

    private function resolveNamedMonth(string $normalized, string $sourceText, CarbonImmutable $now): ?AssistantResolvedPeriod
    {
        foreach (self::MONTHS as $monthName => $month) {
            if (preg_match('/\bза\s+'.preg_quote($monthName, '/').'\b/u', $normalized) !== 1) {
                continue;
            }

            $year = $month < (int) $now->month ? (int) $now->year : (int) $now->year - 1;
            $date = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $now->timezone);

            return $this->period($date->startOfMonth(), $date->endOfMonth(), self::MONTH_LABELS[$month].' '.$year, $sourceText);
        }

        return null;
    }

    private function period(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $label, string $sourceText): AssistantResolvedPeriod
    {
        return new AssistantResolvedPeriod(
            dateFrom: $dateFrom->toDateString(),
            dateTo: $dateTo->toDateString(),
            label: $label,
            sourceText: $sourceText
        );
    }

    private function dateFromParts(int $year, int $month, int $day): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createSafe($year, $month, $day, 0, 0, 0, 'Europe/Moscow');
        } catch (InvalidDateException) {
            return null;
        }
    }

    private function dateFromString(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return $this->dateFromParts($year, $month, $day);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
