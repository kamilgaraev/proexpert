<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;

final class AssistantProductionSchedulePeriod
{
    private const EXPLICIT_DATE = '(?:\d{4}-\d{2}-\d{2}|\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4})';

    private const EXPLICIT_RANGE = '(?:(?:за|в)\s+(?:весь\s+)?период\s+)?(?:с|со)\s+'.self::EXPLICIT_DATE.'\s+по\s+'.self::EXPLICIT_DATE.'\b';

    private const CURRENT_SNAPSHOT_TIME = '(?:сегодняшний\s+день|сегодня|сейчас|текущий\s+(?:момент|день)|текущую\s+дату)';

    private const SNAPSHOT_PREFIX = '(?:по\s+состоянию\s+на|на(?=\s+(?:'.self::CURRENT_SNAPSHOT_TIME.'|вчера|позавчера|завтра|послезавтра|'.self::EXPLICIT_DATE.')\b))\b';

    private const CURRENT_SNAPSHOT = self::SNAPSHOT_PREFIX.'\s+'.self::CURRENT_SNAPSHOT_TIME.'\b';

    private const SUBDAY_UNIT = '(?:час(?:а|ов|ы)?|минут(?:а|у|ы)?|секунд(?:а|у|ы)?)';

    private const SUBDAY_PERIOD = '(?:за\s+(?:[\pL\pN-]+\s+){0,5}|(?:'.self::DURATION_MODIFIER.'\s+)?(?:\d+|'.self::DURATION_NUMBER.')\s+|'.self::DURATION_MODIFIER.'\s+)'.self::SUBDAY_UNIT.'\b';

    private const BARE_CALENDAR_SELECTOR = '(?:эт(?:от|а|у)|(?:текущ|прошл|предыдущ|позапрошл|следующ)(?:ий|ый|ая|ую))';

    private const BARE_CALENDAR_UNIT = '(?:год|месяц|квартал|неделя|неделю|день)';

    private const BARE_CALENDAR_PERIOD = self::BARE_CALENDAR_SELECTOR.'\s+'.self::BARE_CALENDAR_UNIT.'\b';

    private const MONTH = '(?:январ[ьяе]|феврал[ьяе]|март[ае]?|апрел[ьяе]|ма[йея]|июн[ьяе]|июл[ьяе]|август[ае]?|сентябр[ьяе]|октябр[ьяе]|ноябр[ьяе]|декабр[ьяе])';

    private const QUARTER_NUMBER = '(?:[1-4](?:-(?:й|ый|ой|ий|ом))?|IV|I{1,3}|перв(?:ый|ом)|втор(?:ой|ом)|трет(?:ий|ьем)|четв[её]рт(?:ый|ом))';

    private const QUARTER_WORD = 'квартал(?:[ауеы]|ом|ов|ам|ами|ах)?';

    private const QUARTER_SELECTOR = '(?:'.self::QUARTER_NUMBER.'|'.self::DURATION_NUMBER.'|\d+(?:-?[а-яё]+)?|[ivx]+|(?:эт|текущ|прошл|предыдущ|последн|крайн|следующ|позапрошл|прошедш|минувш)[а-яё]*)(?:\s+(?:и\s+)?(?:\d+|'.self::DURATION_NUMBER.'))?\s+';

    private const QUARTER_PERIOD = '(?:(?:за|в|во|на)\s+(?:'.self::QUARTER_SELECTOR.')?|(?:с|со|по|до)\s+'.self::QUARTER_SELECTOR.'|'.self::QUARTER_SELECTOR.')'.self::QUARTER_WORD.'\b';

    private const TIME_UNIT = '(?:недел(?:я|ю|е|и|ь|ей|ям|ями|ях)|месяц(?:а|у|ем|е|ы|ев|ам|ами|ах)?|'.self::QUARTER_WORD.'|год(?:а|у|ом|е|ы|ов|ам|ами|ах)?|лет|день|дня|дни|дней|сутки|суток)';

    private const DURATION_NUMBER = '(?:\d{1,2}|один|одна|одну|два|две|пара|пару|три|четыре|пять|шесть|семь|восемь|девять|десять|одиннадцать|двенадцать)';

    private const DURATION_MODIFIER = '(?:последн(?:ий|ие|их|юю)|крайн(?:ий|ие|их|юю)|предыдущ(?:ие|их)|(?:прошедш|минувш)(?:ий|ую|ие|их))';

    private const DURATION_UNIT = '(?:день|дня|дней|сутки|неделю|недели|недель|месяц|месяца|месяцев|год|года|лет|квартала|кварталов|кварталы)';

    private const DURATION_PERIOD = '(?:(?:за\s+(?:'.self::DURATION_MODIFIER.'\s+)?(?:'.self::DURATION_NUMBER.'\s+)?|(?:'.self::DURATION_MODIFIER.'\s+)?'.self::DURATION_NUMBER.'\s+|'.self::DURATION_MODIFIER.'\s+)'.self::DURATION_UNIT.'\b|за\s+(?:[\pL-]+\s+){1,3}(?:день|дня|дней|сутки|суток|неделю|недели|недель|месяц|месяца|месяцев|месяцы|год|года|годы|лет|квартала|кварталов|кварталы)\b|(?:за\s+)?(?:последн(?:ие|их)\s+)?полгода\b)';

    private const WEEKS_AGO_PERIOD = '(?:за\s+)?(?:\d+|'.self::DURATION_NUMBER.')\s+недел(?:ю|и|ь)\s+назад\b';

    private const CALENDAR_WEEK_PERIOD = '(?:за|в|во|на)\s+(эт(?:ой|у)|текущ(?:ей|ую)|прошл(?:ой|ую)|предыдущ(?:ей|ую)|позапрошл(?:ой|ую))\s+недел(?:е|ю)\b';

    private const WHOLE_PERIOD_PREFIX = '(?:за|в|во|на)\s+в(?:есь|с[её]м|сю|сей|се)\s+';

    private const WHOLE_CALENDAR_PERIOD = self::WHOLE_PERIOD_PREFIX.'(?:\d|'.self::MONTH.'\b|(?:[\pL\pN-]+\s+){0,2}'.self::TIME_UNIT.'\b)';

    private const ALL_TIME_PERIOD = 'за\s+(?:весь\s+период|вс[её]\s+время)\b';

    private const TIME_START = '(?:'.self::SUBDAY_PERIOD.'|'.self::BARE_CALENDAR_PERIOD.'|'.self::DURATION_PERIOD.'|'.self::QUARTER_PERIOD.'|'.self::WEEKS_AGO_PERIOD.'|'.self::CALENDAR_WEEK_PERIOD.'|'.self::WHOLE_CALENDAR_PERIOD.'|'.self::ALL_TIME_PERIOD.'|(?:за|в|во|на|с|со|по)\s+(?:\d|'.self::MONTH.'\b|(?:эт|текущ|прошл|предыдущ|последн|следующ|позапрошл|прошедш|минувш)[а-яё]*\s+(?:\d+\s+)?(?:'.self::TIME_UNIT.'|'.self::MONTH.')\b|начал[а-яё]*\b|конец\b|период\b)|сегодня\b|вчера\b|позавчера\b)';

    public static function withoutSuffix(string $name): string
    {
        $time = '(?:'.self::SNAPSHOT_PREFIX.'|'.self::CURRENT_SNAPSHOT.'|'.self::SUBDAY_PERIOD.'|'.self::BARE_CALENDAR_PERIOD.'|'.self::EXPLICIT_RANGE.'|'.self::DURATION_PERIOD.'|'.self::QUARTER_PERIOD.'|'.self::WEEKS_AGO_PERIOD.'|'.self::CALENDAR_WEEK_PERIOD.'|'.self::WHOLE_CALENDAR_PERIOD.'|'.self::ALL_TIME_PERIOD.'|(?:за|в|во|на)\s+(?:'.self::MONTH.'\b|(?:эт|текущ|прошл|предыдущ|позапрошл|последн|следующ|прошедш|минувш)[а-яё]*\s+(?:\d+\s+)?'.self::TIME_UNIT.'\b|\d+\s+'.self::TIME_UNIT.'\b)'
            .'|(?:с|со)\s+(?:'.self::EXPLICIT_DATE.'|\d{1,2}\s+по\b|начала\b)|(?:за|в|во|на)\s+(?:19|20)\d{2}(?:\s+год[ау]?)?\b(?![-.\/]\d)|(?:за\s+)?(?:сегодня|вчера|позавчера)\b)';
        $parts = preg_split('/\s+(?='.$time.')/iu', $name, 2);

        $head = trim((string) ($parts[0] ?? $name));

        return count($parts) > 1 ? (preg_replace('/[\s,;:—–\-]+$/u', '', $head) ?? $head) : $head;
    }

    public static function arguments(int $projectId, array $plan): array
    {
        $arguments = ['project_id' => $projectId];
        $override = $plan['production_period_override'] ?? null;
        if (is_array($override) && ($override['message_sha256'] ?? null) === hash('sha256', (string) ($plan['request']['message'] ?? ''))
            && ($override['project_id'] ?? null) === $projectId) {
            $dates = array_intersect_key($override, array_flip(['project_id', 'period_start', 'period_end']));
            if (array_key_exists('period_start', $dates) && isset($dates['period_end']) && self::validArguments($dates)) {
                return ['project_id' => $projectId, 'period_start' => $dates['period_start'], 'period_end' => $dates['period_end']];
            }
        }
        $query = self::normalizedQuery(AssistantNamedProjectReadScope::periodQuery($plan));
        $unresolved = $arguments + ['period_start' => null, 'period_end' => null];
        if (preg_match('/\b(?:'.self::SNAPSHOT_PREFIX.'|'.self::SUBDAY_PERIOD.')/iu', $query) === 1) {
            return $unresolved;
        }
        $withoutRanges = preg_replace('/\b'.self::EXPLICIT_RANGE.'/iu', ' ', $query) ?? $query;
        if (preg_match_all('/\b'.self::ALL_TIME_PERIOD.'/iu', $withoutRanges, $allTime) > 0) {
            return count($allTime[0]) === 1 && ! self::hasOtherPeriod($query, $allTime[0][0]) ? $arguments : $unresolved;
        }
        if (preg_match('/\b'.self::WHOLE_CALENDAR_PERIOD.'/iu', $query) === 1) {
            return $unresolved;
        }
        if (preg_match_all('/\b(?:с|со)\s+начала\s+(?:((?:19|20)\d{2}|этого|текущего|прошлого|предыдущего|позапрошлого|этой|текущей|прошлой|предыдущей|позапрошлой)\s+)?(года|квартала|месяца|недели|дня|суток)(?:\s+по\s+(?:сегодня|текущий\s+день))?\b/iu', $query, $calendarStart) > 0) {
            if (count($calendarStart[0]) !== 1 || self::hasOtherPeriod($query, $calendarStart[0][0])) {
                return $unresolved;
            }
            $relative = mb_strtolower($calendarStart[1][0]);
            $unit = mb_strtolower($calendarStart[2][0]);
            if (ctype_digit($relative) && $unit !== 'года') {
                return $unresolved;
            }
            $offset = str_starts_with($relative, 'позапрошл') ? 2 : ((str_starts_with($relative, 'прошл') || str_starts_with($relative, 'предыдущ')) ? 1 : 0);
            $today = new \DateTimeImmutable(\Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d'));
            $start = match ($unit) {
                'года' => $today->setDate(ctype_digit($relative) ? (int) $relative : (int) $today->format('Y') - $offset, 1, 1),
                'квартала' => $today->setDate((int) $today->format('Y'), intdiv((int) $today->format('n') - 1, 3) * 3 + 1, 1)->modify('-'.($offset * 3).' months'),
                'месяца' => $today->modify('first day of this month')->modify('-'.$offset.' months'),
                'недели' => $today->modify('-'.((int) $today->format('N') - 1 + $offset * 7).' days'),
                default => $today->modify('-'.$offset.' days'),
            };

            return $start > $today ? $unresolved : $arguments + ['period_start' => $start->format('Y-m-d'), 'period_end' => $today->format('Y-m-d')];
        }
        if (preg_match_all('/\b(?:с|со)\s+начала\s+(?:проект[а-яё]*|объект[а-яё]*|работ[а-яё]*|строительств[а-яё]*)(?:\s+по\s+(?:сегодня|текущий\s+день))?\b/iu', $query, $projectStart) > 0) {
            return count($projectStart[0]) === 1 && ! self::hasOtherPeriod($query, $projectStart[0][0])
                ? $arguments + ['period_start' => null, 'period_end' => \Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d')] : $unresolved;
        }
        if (preg_match_all('/\b(?:за|в|во|на)\s+позапрошл(?:ый|ом)\s+(год(?:у)?|месяц(?:е)?)\b/iu', $query, $older) > 0) {
            if (count($older[0]) !== 1 || self::hasOtherPeriod($query, $older[0][0]) || preg_match('/\b(?:19|20)\d{2}\b/u', $query) === 1) {
                return $unresolved;
            }
            $today = new \DateTimeImmutable(\Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d'));
            $isYear = str_starts_with(mb_strtolower($older[1][0]), 'год');
            $start = $isYear ? $today->setDate((int) $today->format('Y') - 2, 1, 1) : $today->modify('first day of this month')->modify('-2 months');

            return $arguments + ['period_start' => $start->format('Y-m-d'), 'period_end' => $isYear ? $start->format('Y').'-12-31' : $start->format('Y-m-t')];
        }
        if (preg_match_all('/\b(?:сегодня|вчера|позавчера)\b/iu', $query, $days) > 0) {
            if (count($days[0]) !== 1 || self::hasOtherPeriod($query, $days[0][0])) {
                return $unresolved;
            }
            $offset = ['сегодня' => 0, 'вчера' => 1, 'позавчера' => 2][mb_strtolower($days[0][0])];
            $date = \Carbon\CarbonImmutable::now('Europe/Moscow')->subDays($offset)->format('Y-m-d');

            return $arguments + ['period_start' => $date, 'period_end' => $date];
        }
        if (preg_match_all('/\b'.self::CALENDAR_WEEK_PERIOD.'/iu', $query, $weeks) > 0) {
            if (count($weeks[0]) !== 1 || self::hasOtherPeriod($query, $weeks[0][0])) {
                return $unresolved;
            }
            $relative = mb_strtolower($weeks[1][0]);
            $offset = str_starts_with($relative, 'позапрошл') ? 2 : ((str_starts_with($relative, 'прошл') || str_starts_with($relative, 'предыдущ')) ? 1 : 0);
            $today = new \DateTimeImmutable(\Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d'));
            $start = $today->modify('-'.((int) $today->format('N') - 1 + $offset * 7).' days');
            $end = $offset > 0 ? $start->modify('+6 days') : $today;

            return $arguments + ['period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')];
        }
        if (preg_match_all('/\b(?:'.self::WEEKS_AGO_PERIOD.'|'.self::DURATION_PERIOD.')/iu', $query, $durations) > 0) {
            if (count($durations[0]) !== 1 || self::hasOtherPeriod($query, $durations[0][0])) {
                return $unresolved;
            }
            if (preg_match('/\b'.self::WEEKS_AGO_PERIOD.'/iu', $durations[0][0]) === 1
                && preg_match('/^(?:за\s+)?\d{1,2}\s+/iu', $durations[0][0]) !== 1) {
                return $unresolved;
            }
            $period = (new AssistantPeriodResolver)->resolve($query);

            return $period?->dateFrom !== null && $period->dateTo !== null
                ? $arguments + ['period_start' => $period->dateFrom, 'period_end' => $period->dateTo] : $unresolved;
        }
        if (preg_match('/\b'.self::QUARTER_PERIOD.'/iu', $query) === 1) {
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
        $monthCount = preg_match_all('/\b'.self::MONTH.'\b/iu', $query);
        if ($monthCount > 1 || ($monthCount === 1 && (preg_match_all('/\b(?:19|20)\d{2}\b/u', $query) > 1
            || preg_match('/\bза\s+'.self::MONTH.'\s+(?:(?:19|20)\d{2}\b|(?:этого|текущего|прошлого)\s+года\b)/iu', $query) !== 1))) {
            return $unresolved;
        }
        $resolver = new AssistantPeriodResolver;
        if (preg_match_all('/\b'.self::EXPLICIT_RANGE.'/iu', $query, $ranges) > 0) {
            if (count($ranges[0]) !== 1 || self::hasOtherPeriod($query, $ranges[0][0])) {
                return $unresolved;
            }
            if (preg_match('/\b(?:с|со)\s+(\d{4}-\d{2}-\d{2})\s+по\s+(\d{4}-\d{2}-\d{2})\b/iu', $ranges[0][0], $dates) === 1) {
                $period = $resolver->resolve(['date_from' => $dates[1], 'date_to' => $dates[2]]);
            } else {
                $period = $resolver->resolve(preg_replace('/\bсо\s+(?=\d)/iu', 'с ', $ranges[0][0]) ?? $ranges[0][0]);
            }
        } elseif (preg_match('/\b(?:с|со|по|до|на|от)\s+\d{1,2}(?!\d)|\bза\s+\d{1,2}(?!\d)\s+'.self::MONTH.'/iu', $query) === 1) {
            return $unresolved;
        } else {
            if (preg_match_all('/\b'.self::TIME_START.'/iu', $query) !== 1) {
                return $unresolved;
            }
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
            if ($key === 'period_start' && $arguments[$key] === null) {
                continue;
            }
            if (! is_string($arguments[$key]) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $arguments[$key], $date) !== 1
                || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                return false;
            }
        }

        return $arguments['period_start'] === null || $arguments['period_start'] <= $arguments['period_end'];
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
            && AssistantDetailContinuationIntent::matches($reply, includeFinancial: false)) {
            $arguments = array_intersect_key($override, array_flip(['project_id', 'period_start', 'period_end']));
            if (array_key_exists('period_start', $arguments) && isset($arguments['period_end']) && self::validArguments($arguments)) {
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
        if (preg_match('/^'.self::EXPLICIT_RANGE.'[.!?]?$/iu', $reply) === 1) {
            $dates = self::arguments($pending['project_id'], ['request' => ['message' => $reply]]);

            return isset($dates['period_start'], $dates['period_end']) && self::validArguments($dates)
                ? ['query' => $query] + $dates : null;
        }
        $pattern = '/\b(?:за\s+'.self::MONTH.'|(?:за|в|во|на)\s+'.self::QUARTER_NUMBER.'\s+квартал[ае]?)(?:\s+(?:(?:19|20)\d{2}|(?:этого|текущего|прошлого)\s+года))?/iu';
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
            $context[$key] = [...array_values(array_filter($refs, static fn ($ref): bool => is_array($ref) && ($ref['type'] ?? null) !== 'project' && ($ref['entity_type'] ?? null) !== 'project')),
                ['type' => 'project', 'id' => $clarification['project_id']]];
        }
        $payload['context'] = $context;

        return $payload;
    }

    private static function quarterDates(string $query): array
    {
        $unresolved = ['period_start' => null, 'period_end' => null];
        if (preg_match_all('/\b'.self::QUARTER_PERIOD.'/iu', $query) !== 1 || preg_match('/\b'.self::MONTH.'\b/iu', $query) === 1) {
            return $unresolved;
        }
        $years = preg_match_all('/\b(?:19|20)\d{2}\b/u', $query);
        if ($years === 0 && preg_match('/\bза\s+квартал\b/iu', $query, $rolling) === 1) {
            if (self::hasOtherPeriod($query, $rolling[0])) {
                return $unresolved;
            }
            $period = (new AssistantPeriodResolver)->resolve($query);

            return $period?->dateFrom !== null && $period->dateTo !== null
                ? ['period_start' => $period->dateFrom, 'period_end' => $period->dateTo] : $unresolved;
        }
        if ($years === 0 && preg_match('/\b(?:за|в|во|на)\s+(эт(?:от|ом)|текущ(?:ий|ем)|прошл(?:ый|ом)|предыдущ(?:ий|ем)|позапрошл(?:ый|ом))\s+квартал[е]?\b/iu', $query, $relative) === 1) {
            if (self::hasOtherPeriod($query, $relative[0])) {
                return $unresolved;
            }
            $end = new \DateTimeImmutable(\Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d'));
            $start = $end->setDate((int) $end->format('Y'), intdiv((int) $end->format('n') - 1, 3) * 3 + 1, 1);
            $offset = preg_match('/^позапрошл/iu', $relative[1]) === 1 ? 2 : (preg_match('/^(?:прошл|предыдущ)/iu', $relative[1]) === 1 ? 1 : 0);
            if ($offset > 0) {
                $start = $start->modify('-'.($offset * 3).' months');
                $end = $start->modify('+3 months')->modify('-1 day');
            }

            return ['period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')];
        }
        if ($years !== 1 || preg_match('/\b(?:за|в|во|на)\s+('.self::QUARTER_NUMBER.')\s+квартал[ае]?\s+((?:19|20)\d{2})\b/iu', $query, $quarter) !== 1
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
        $remainder = str_replace($phrase, ' ', $query);

        return preg_match('/\b'.self::TIME_START.'/iu', $remainder) === 1;
    }

    private static function normalizedQuery(string $query): string
    {
        $query = preg_replace('/\b'.self::CURRENT_SNAPSHOT.'/iu', ' ', $query) ?? $query;
        $query = preg_replace('/\b(?:за\s+)?предыдущ(?:ие|их)\s+(?='.self::DURATION_NUMBER.'\s+'.self::TIME_UNIT.'\b)/iu', 'за ', $query) ?? $query;
        $query = preg_replace_callback('/\b'.self::WHOLE_PERIOD_PREFIX.'(эт(?:от|ом|у|ой)|текущ(?:ий|ем|ую|ей)|прошл(?:ый|ом|ую|ой)|предыдущ(?:ий|ем|ую|ей)|позапрошл(?:ый|ом|ую|ой))\s+(год(?:у)?|месяц(?:е)?|квартал(?:е)?|недел(?:ю|е)|день)\b/iu', static function (array $match): string {
            $relative = mb_strtolower($match[1]);
            $unit = mb_strtolower($match[2]);
            $offset = str_starts_with($relative, 'позапрошл') ? 2 : ((str_starts_with($relative, 'прошл') || str_starts_with($relative, 'предыдущ')) ? 1 : 0);
            $today = new \DateTimeImmutable(\Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y-m-d'));
            $start = match (true) {
                str_starts_with($unit, 'год') => $today->setDate((int) $today->format('Y') - $offset, 1, 1),
                str_starts_with($unit, 'месяц') => $today->modify('first day of this month')->modify('-'.$offset.' months'),
                str_starts_with($unit, 'квартал') => $today->setDate((int) $today->format('Y'), intdiv((int) $today->format('n') - 1, 3) * 3 + 1, 1)->modify('-'.($offset * 3).' months'),
                str_starts_with($unit, 'недел') => $today->modify('-'.((int) $today->format('N') - 1 + $offset * 7).' days'),
                default => $today->modify('-'.$offset.' days'),
            };
            $end = match (true) {
                str_starts_with($unit, 'год') => $start->modify('+1 year')->modify('-1 day'),
                str_starts_with($unit, 'месяц') => $start->modify('last day of this month'),
                str_starts_with($unit, 'квартал') => $start->modify('+3 months')->modify('-1 day'),
                str_starts_with($unit, 'недел') => $start->modify('+6 days'),
                default => $start,
            };

            return 'с '.$start->format('Y-m-d').' по '.$end->format('Y-m-d');
        }, $query) ?? $query;
        $query = preg_replace_callback('/\b(?:(за|в|во|на|с|со|по|до)\s+)?('.self::BARE_CALENDAR_SELECTOR.')\s+('.self::BARE_CALENDAR_UNIT.')\b/iu', static function (array $match): string {
            if (($match[1] ?? '') !== '') {
                return $match[0];
            }
            $adjective = mb_strtolower($match[2]);
            $unit = mb_strtolower($match[3]);
            if ($unit === 'неделя' || $unit === 'неделю') {
                $unit = 'неделю';
                $adjective = $adjective === 'эта' ? 'эту' : (preg_replace('/ая$/u', 'ую', $adjective) ?? $adjective);
            }

            return 'за '.$adjective.' '.$unit;
        }, $query) ?? $query;
        $query = preg_replace('/\b((?:за|в|во|на)\s+)в(?:есь|с[её]м|сю|сей|се)\s+(?=(?:19|20)\d{2}\b(?![-.\/]\d)|'.self::MONTH.'\b|'.self::QUARTER_NUMBER.'\s+'.self::QUARTER_WORD.'\b)/iu', '$1', $query) ?? $query;
        $query = preg_replace_callback('/\b((?:за|в|во|на)\s+'.self::QUARTER_NUMBER.'\s+квартал[ае]?)\s+(этого|текущего|прошлого|предыдущего|позапрошлого)\s+года\b/iu', static function (array $match): string {
            $relative = mb_strtolower($match[2]);
            $offset = str_starts_with($relative, 'позапрошл') ? 2 : ((str_starts_with($relative, 'прошл') || str_starts_with($relative, 'предыдущ')) ? 1 : 0);

            return $match[1].' '.((int) \Carbon\CarbonImmutable::now('Europe/Moscow')->format('Y') - $offset);
        }, $query) ?? $query;
        $query = preg_replace_callback('/\bза\s+(этот|текущий|прошлый|предыдущий|позапрошлый)\s+день\b/iu', static function (array $match): string {
            $relative = mb_strtolower($match[1]);

            return str_starts_with($relative, 'позапрошл') ? 'позавчера' : ((str_starts_with($relative, 'прошл') || str_starts_with($relative, 'предыдущ')) ? 'вчера' : 'сегодня');
        }, $query) ?? $query;
        $query = preg_replace_callback('/\b(?:за\s+)?(?:(?:(?:последн|крайн)(?:ий|юю)|(?:прошедш|минувш)(?:ий|ую))\s+(день|неделю|месяц|год|квартал)|(?:последн|крайн|прошедш|минувш)(?:ие|их)\s+(сутки))\b/iu', static function (array $match): string {
            $unit = mb_strtolower($match[1] !== '' ? $match[1] : $match[2]);

            return $unit === 'квартал' ? 'за квартал' : 'за 1 '.$unit;
        }, $query) ?? $query;
        $query = preg_replace_callback('/\b(?:за|в|во|на)\s+(эт(?:от|ом)|текущ(?:ий|ем)|прошл(?:ый|ом)|предыдущ(?:ий|ем))\s+(год(?:у)?|месяц(?:е)?)\b/iu', static function (array $match): string {
            $relative = mb_strtolower($match[1]);
            $adjective = str_starts_with($relative, 'эт') ? 'этот' : (str_starts_with($relative, 'текущ') ? 'текущий' : 'прошлый');

            return 'за '.$adjective.' '.(str_starts_with(mb_strtolower($match[2]), 'год') ? 'год' : 'месяц');
        }, $query) ?? $query;
        $months = ['январе' => 'январь', 'феврале' => 'февраль', 'марте' => 'март', 'апреле' => 'апрель', 'мае' => 'май', 'июне' => 'июнь',
            'июле' => 'июль', 'августе' => 'август', 'сентябре' => 'сентябрь', 'октябре' => 'октябрь', 'ноябре' => 'ноябрь', 'декабре' => 'декабрь'];

        return preg_replace_callback('/\b(?:в|во|на)\s+('.self::MONTH.')\b/iu', static function (array $match) use ($months): string {
            $month = mb_strtolower($match[1]);

            return 'за '.($months[$month] ?? $month);
        }, $query) ?? $query;
    }
}
