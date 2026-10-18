<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;

final class AssistantProductionSchedulePeriod
{
    private const MONTH = '(?:январ[ьяе]|феврал[ьяе]|март[ае]?|апрел[ьяе]|ма[йея]|июн[ьяе]|июл[ьяе]|август[ае]?|сентябр[ьяе]|октябр[ьяе]|ноябр[ьяе]|декабр[ьяе])';

    private const QUARTER_NUMBER = '(?:[1-4](?:-(?:й|ый|ой|ий|ом))?|IV|I{1,3}|перв(?:ый|ом)|втор(?:ой|ом)|трет(?:ий|ьем)|четв[её]рт(?:ый|ом))';

    private const TIME_START = '(?:(?:за|в|во|на|с|со|по)\s+(?:\d|'.self::MONTH.'\b|(?:эт|текущ|прошл|предыдущ|последн|следующ|позапрошл)[а-яё]*\s+(?:\d+\s+)?(?:недел|месяц|квартал|год|день|дня|дней|сут|'.self::MONTH.')|начал[а-яё]*\b|конец\b|период\b)|сегодня\b|вчера\b|позавчера\b)';

    public static function withoutSuffix(string $name): string
    {
        $time = '(?:(?:за|в|во)\s+(?:'.self::QUARTER_NUMBER.'\s+квартал[ае]?\b|'.self::MONTH.'\b|(?:эт|текущ|прошл|предыдущ|последн|следующ)[а-яё]*\s+(?:недел|месяц|квартал|год)|\d+\s+(?:недел|месяц|квартал|год|дней))'
            .'|(?:с|со)\s+(?:\d{4}-\d{2}-\d{2}|\d{1,2}[.\/]\d{1,2}[.\/]\d{4}|\d{1,2}\s+по\b|начала\b)|(?:за|в|во|на)\s+(?:19|20)\d{2}(?:\s+год[ау]?)?\b(?![-.\/]\d))';
        $parts = preg_split('/\s+(?='.$time.')/iu', $name, 2);

        return trim((string) ($parts[0] ?? $name));
    }

    public static function arguments(int $projectId, array $plan): array
    {
        $arguments = ['project_id' => $projectId];
        $override = $plan['production_period_override'] ?? null;
        if (is_array($override) && ($override['message_sha256'] ?? null) === hash('sha256', (string) ($plan['request']['message'] ?? ''))
            && ($override['project_id'] ?? null) === $projectId) {
            $dates = array_intersect_key($override, array_flip(['project_id', 'period_start', 'period_end']));
            if (isset($dates['period_start'], $dates['period_end']) && self::validArguments($dates)) {
                return ['project_id' => $projectId, 'period_start' => $dates['period_start'], 'period_end' => $dates['period_end']];
            }
        }
        $query = self::normalizedQuery(AssistantNamedProjectReadScope::periodQuery($plan));
        if (preg_match('/\bквартал[а-яё]*\b/iu', $query) === 1) {
            return $arguments + self::quarterDates($query);
        }
        if (preg_match('/\b(?:за|в|во|на)\s+((?:19|20)\d{2})(?:\s+год[ау]?)?\b(?![-.\/]\d)/iu', $query, $year) === 1) {
            return $arguments + (preg_match_all('/\b(?:19|20)\d{2}\b/u', $query) === 1
                && preg_match('/\b'.self::MONTH.'\b/iu', $query) !== 1 && preg_match_all('/\b'.self::TIME_START.'/iu', $query) === 1
                ? ['period_start' => $year[1].'-01-01', 'period_end' => $year[1].'-12-31'] : ['period_start' => null, 'period_end' => null]);
        }
        if (preg_match('/\b'.self::TIME_START.'/iu', $query) !== 1) {
            return $arguments;
        }
        $unresolved = $arguments + ['period_start' => null, 'period_end' => null];
        $monthCount = preg_match_all('/\b'.self::MONTH.'\b/iu', $query);
        if ($monthCount > 1 || ($monthCount === 1 && preg_match('/\bза\s+'.self::MONTH.'\s+(?:(?:19|20)\d{2}\b|(?:этого|текущего|прошлого)\s+года\b)/iu', $query) !== 1)) {
            return $unresolved;
        }
        $resolver = new AssistantPeriodResolver;
        if (preg_match('/\bс\s+(\d{4}-\d{2}-\d{2})\s+по\s+(\d{4}-\d{2}-\d{2})\b/iu', $query, $dates) === 1) {
            $period = $resolver->resolve(['date_from' => $dates[1], 'date_to' => $dates[2]]);
        } elseif (preg_match('/\bс\s+\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\s+по\s+\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\b/iu', $query) === 1) {
            $period = $resolver->resolve($query);
        } elseif (preg_match('/\b(?:с|со|по|до|на|от)\s+\d{1,2}(?!\d)|\bза\s+\d{1,2}(?!\d)\s+'.self::MONTH.'/iu', $query) === 1) {
            return $unresolved;
        } else {
            $period = $resolver->resolve($query);
        }

        return $period?->dateFrom !== null && $period->dateTo !== null
            ? $arguments + ['period_start' => $period->dateFrom, 'period_end' => $period->dateTo] : $unresolved;
    }

    public static function validArguments(array $arguments): bool
    {
        if (! is_int($arguments['project_id'] ?? null) || $arguments['project_id'] < 1
            || array_diff(array_keys($arguments), ['project_id', 'period_start', 'period_end']) !== []) {
            return false;
        }
        if (! array_key_exists('period_start', $arguments) && ! array_key_exists('period_end', $arguments)) {
            return true;
        }
        if (! array_key_exists('period_start', $arguments) || ! array_key_exists('period_end', $arguments)) {
            return false;
        }
        if ($arguments['period_start'] === null && $arguments['period_end'] === null) {
            return true;
        }
        foreach (['period_start', 'period_end'] as $key) {
            if (! is_string($arguments[$key]) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $arguments[$key], $date) !== 1
                || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                return false;
            }
        }

        return $arguments['period_start'] <= $arguments['period_end'];
    }

    public static function pending(array $plan, array $verified): ?array
    {
        $ref = $verified['source_refs'][0] ?? null;
        $arguments = is_array($ref) ? ($ref['_read_arguments'] ?? null) : null;
        if (($verified['replaced'] ?? false) !== true || ($verified['needs_clarification'] ?? false) !== true
            || ($ref['entity_type'] ?? null) !== \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader::TYPE
            || ! is_array($arguments) || ! self::validArguments($arguments) || ! array_key_exists('period_start', $arguments)
            || $arguments['period_start'] !== null || $arguments['period_end'] !== null) {
            return null;
        }

        return ['message' => (string) ($plan['request']['message'] ?? ''), 'project_id' => $arguments['project_id'],
            'period_query' => self::normalizedQuery(AssistantNamedProjectReadScope::periodQuery($plan))];
    }

    public static function clarification(string $reply, array $context): ?array
    {
        $previous = $context['last_request'] ?? [];
        $override = $previous['production_period_override'] ?? null;
        if (is_array($override) && is_string($previous['message'] ?? null)
            && ($override['message_sha256'] ?? null) === hash('sha256', $previous['message'])
            && preg_match('/^\s*(?:покажи(?:те)?\s+)?подробнее[.!?]?\s*$/iu', $reply) === 1) {
            $arguments = array_intersect_key($override, array_flip(['project_id', 'period_start', 'period_end']));
            if (isset($arguments['period_start'], $arguments['period_end']) && self::validArguments($arguments)) {
                return ['query' => $previous['message']] + $arguments;
            }
        }
        $pending = $context['pending_production_period'] ?? null;
        if (! is_array($pending) || ($pending['message'] ?? null) !== ($context['last_request']['message'] ?? null)
            || ! is_int($pending['project_id'] ?? null) || $pending['project_id'] < 1
            || ! is_string($pending['period_query'] ?? null) || mb_strlen($pending['period_query']) > 4000) {
            return null;
        }
        $reply = trim(preg_replace('/\s+/u', ' ', $reply) ?? $reply);
        $query = $pending['period_query'];
        if (preg_match('/^с\s+(\d{4}-\d{2}-\d{2})\s+по\s+(\d{4}-\d{2}-\d{2})[.!?]?$/iu', $reply, $dates) === 1
            && self::validArguments(['project_id' => $pending['project_id'], 'period_start' => $dates[1], 'period_end' => $dates[2]])) {
            return ['query' => $query, 'project_id' => $pending['project_id'], 'period_start' => $dates[1], 'period_end' => $dates[2]];
        }
        $pattern = '/\b(?:за\s+'.self::MONTH.'|(?:за|в|во)\s+'.self::QUARTER_NUMBER.'\s+квартал[ае]?)(?:\s+(?:(?:19|20)\d{2}|(?:этого|текущего|прошлого)\s+года))?/iu';
        if (preg_match_all($pattern, $query) !== 1) {
            return null;
        }
        if (preg_match('/^(?:за\s+)?((?:19|20)\d{2})(?:\s+г(?:од(?:а|у)?)?\.?)?[.!?]?$/iu', $reply, $year) === 1) {
            $query = preg_replace_callback($pattern, static fn (array $month): string => preg_replace('/\s+(?:19|20)\d{2}$/u', '', $month[0]).' '.$year[1], $query, 1) ?? $query;
        } else {
            return null;
        }

        return ['query' => $query, 'project_id' => $pending['project_id']];
    }

    public static function resumePayload(array $payload, array $clarification): array
    {
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        foreach (['entity_refs', 'entity_references'] as $key) {
            $refs = is_array($context[$key] ?? null) ? $context[$key] : [];
            $context[$key] = [...array_values(array_filter($refs, static fn ($ref): bool => is_array($ref) && ($ref['type'] ?? null) !== 'project')),
                ['type' => 'project', 'id' => $clarification['project_id']]];
        }
        $payload['context'] = $context;

        return $payload;
    }

    private static function quarterDates(string $query): array
    {
        $unresolved = ['period_start' => null, 'period_end' => null];
        if (preg_match_all('/\bквартал[а-яё]*\b/iu', $query) !== 1 || preg_match('/\b'.self::MONTH.'\b/iu', $query) === 1) {
            return $unresolved;
        }
        $years = preg_match_all('/\b(?:19|20)\d{2}\b/u', $query);
        if ($years === 0 && preg_match('/\bза\s+(?:последний\s+)?квартал\b/iu', $query, $rolling) === 1) {
            if (self::hasOtherPeriod($query, $rolling[0])) {
                return $unresolved;
            }
            $period = (new AssistantPeriodResolver)->resolve($query);

            return $period?->dateFrom !== null && $period->dateTo !== null
                ? ['period_start' => $period->dateFrom, 'period_end' => $period->dateTo] : $unresolved;
        }
        if ($years === 0 && preg_match('/\b(?:за|в|во)\s+(эт(?:от|ом)|текущ(?:ий|ем)|прошл(?:ый|ом)|предыдущ(?:ий|ем))\s+квартал[е]?\b/iu', $query, $relative) === 1) {
            if (self::hasOtherPeriod($query, $relative[0])) {
                return $unresolved;
            }
            $end = new \DateTimeImmutable(\Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d'));
            $start = $end->setDate((int) $end->format('Y'), intdiv((int) $end->format('n') - 1, 3) * 3 + 1, 1);
            if (preg_match('/^(?:прошл|предыдущ)/iu', $relative[1]) === 1) {
                $end = $start->modify('-1 day');
                $start = $start->modify('-3 months');
            }

            return ['period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')];
        }
        if ($years !== 1 || preg_match('/\b(?:за|в|во)\s+('.self::QUARTER_NUMBER.')\s+квартал[ае]?\s+((?:19|20)\d{2})\b/iu', $query, $quarter) !== 1
            || self::hasOtherPeriod($query, $quarter[0])) {
            return $unresolved;
        }
        $number = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'первый' => 1, 'первом' => 1, 'второй' => 2, 'втором' => 2,
            'третий' => 3, 'третьем' => 3, 'четвертый' => 4, 'четвертом' => 4, 'четвёртый' => 4, 'четвёртом' => 4][mb_strtolower($quarter[1])] ?? (int) $quarter[1];
        $start = new \DateTimeImmutable(sprintf('%d-%02d-01', (int) $quarter[2], ($number - 1) * 3 + 1));

        return ['period_start' => $start->format('Y-m-d'), 'period_end' => $start->modify('+2 months')->format('Y-m-t')];
    }

    private static function hasOtherPeriod(string $query, string $phrase): bool
    {
        return preg_match('/\b'.self::TIME_START.'/iu', str_replace($phrase, ' ', $query)) === 1;
    }

    private static function normalizedQuery(string $query): string
    {
        $months = ['январе' => 'январь', 'феврале' => 'февраль', 'марте' => 'март', 'апреле' => 'апрель', 'мае' => 'май', 'июне' => 'июнь',
            'июле' => 'июль', 'августе' => 'август', 'сентябре' => 'сентябрь', 'октябре' => 'октябрь', 'ноябре' => 'ноябрь', 'декабре' => 'декабрь'];

        return preg_replace_callback('/\b(?:в|во)\s+('.self::MONTH.')\b/iu', static function (array $match) use ($months): string {
            $month = mb_strtolower($match[1]);

            return 'за '.($months[$month] ?? $month);
        }, $query) ?? $query;
    }
}
