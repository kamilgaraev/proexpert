<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;
use Throwable;

final class AssistantNamedProjectReadScope
{
    public static function bind(array $plan, callable $search): array
    {
        unset($plan['named_project_scope']);
        $query = (string) ($plan['request']['message'] ?? '');
        $intent = AssistantOperationalReaderRegistry::intent($query);
        if (! isset(AssistantOperationalReaderRegistry::TOOLS[$intent['name'] ?? '']) || AssistantRequestProjectScope::organizationWide($query)) {
            return $plan;
        }
        $name = self::name($plan);
        $ambiguous = AssistantReportEntityQuery::hasAmbiguousName($query, 'project', preservePrepositions: true);
        $implicitQuestion = false;
        if ($name === null && ! $ambiguous && self::hasImplicitProjectClause($query)) {
            $name = AssistantReportEntityQuery::extract($query, 'project', preservePrepositions: true);
            $name = $name === null ? null : preg_replace('/[.!?]+$/u', '', $name);
            $implicitQuestion = $name !== null;
        }
        if ($name === null && ! $ambiguous) {
            return $plan;
        }
        $id = null;
        $nameQuery = $name;
        $implicitNotName = false;
        if ($name !== null && ! $ambiguous) {
            $arguments = ['query' => $name, 'limit' => 2];
            if (preg_match('/^(?:id\s*|№\s*|#\s*)?([1-9]\d*)$/iu', $name, $matches) === 1
                && (! self::hasQuotedName($query) || preg_match('/^(?:id|№|#)/iu', $name) === 1)) {
                $arguments['project_id'] = (int) $matches[1];
            } else {
                $arguments['name_match'] = 'exact';
            }
            try {
                $result = $search($arguments);
                $rows = is_array($result) ? ($result['results'] ?? null) : null;
                if (! isset($arguments['project_id']) && ! self::hasQuotedName($query) && ! $implicitQuestion) {
                    $periodName = AssistantProductionSchedulePeriod::withoutSuffix($name);
                    foreach (array_unique([$periodName, self::withoutScheduleComparison($periodName)]) as $shortName) {
                        if ($shortName === $arguments['query'] || $shortName === '') {
                            continue;
                        }
                        if (! is_array($result) || ($result['status'] ?? null) !== 'success' || $rows !== []
                            || ($result['result_window']['has_more'] ?? null) !== false) {
                            break;
                        }
                        $arguments['query'] = $nameQuery = $shortName;
                        $result = $search($arguments);
                        $rows = is_array($result) ? ($result['results'] ?? null) : null;
                    }
                }
                if (! isset($arguments['project_id']) && ! $implicitQuestion && is_array($result)
                    && ($result['status'] ?? null) === 'success' && is_array($rows)
                    && $rows === [] && ($result['result_window']['has_more'] ?? null) === false) {
                    unset($arguments['name_match']);
                    $result = $search($arguments);
                    $rows = is_array($result) ? ($result['results'] ?? null) : null;
                }
                if (is_array($result) && ($result['status'] ?? null) === 'success' && is_array($rows) && count($rows) === 1
                    && ($result['result_window']['has_more'] ?? null) === false && is_int($rows[0]['id'] ?? null) && $rows[0]['id'] > 0) {
                    $id = $rows[0]['id'];
                }
                $implicitNotName = is_array($result) && ($result['status'] ?? null) === 'success'
                    && $rows === [] && ($result['result_window']['has_more'] ?? null) === false;
            } catch (Throwable) {
                $id = null;
            }
        }
        if ($implicitQuestion && $implicitNotName) {
            return $plan;
        }
        $plan['named_project_scope'] = ['project_id' => $id, 'message_sha256' => hash('sha256', $query), 'name_query' => $nameQuery];

        return $plan;
    }

    public static function name(array $plan): ?string
    {
        $query = (string) ($plan['request']['message'] ?? '');
        if (AssistantRequestProjectScope::organizationWide($query)) {
            return null;
        }
        $quoted = self::hasQuotedName($query);
        if (! $quoted && self::hasImplicitProjectClause($query)) {
            return null;
        }
        $name = AssistantReportEntityQuery::extract($query, 'project', preservePrepositions: true);
        if (! $quoted && $name !== null) {
            $continuation = '(?:ска(?:жи(?:те)?|зать)|расска(?:жи(?:те)?|зать)|пока(?:жи(?:те)?|зать)|провер(?:ь(?:те)?|ить)|свер(?:ь(?:те)?|ить)|сравни(?:те|ть)?|сопостав(?:ь(?:те)?|ить)|ука(?:жи(?:те)?|зать)|наз(?:ови(?:те)?|вать)|уточни(?:те|ть)?|сформируй(?:те)?|создай(?:те)?|удали(?:те)?|измени(?:те)?|закрой(?:те)?|отправь(?:те)?|подпиши(?:те)?|назначь(?:те)?|назначит[а-яё]*|перенес[а-яё]*|перенос[а-яё]*|сдвин[а-яё]*|обнови[а-яё]*|добавь(?:те)?|добавит[а-яё]*|поменяй(?:те)?|исправь(?:те)?|заплат[а-яё]*|оплати[а-яё]*|нужно|надо|можно|что|кто|где|когда|как|какие|какой|какая|какое|каков[а-яё]*|сколько)(?![\pL\pN])';
            $parts = preg_split('/(?:\s+(?:(?:и|а|но)\s+)?|[.!?,;:]\s*)(?='.$continuation.')|[!?]/iu', $name, 2);
            $name = trim((string) ($parts[0] ?? ''));
            preg_match_all('/\s+(?:и|или|а|но)\s+|\s*[,;:—–]\s*|\s+-\s+/iu', $name, $boundaries, PREG_OFFSET_CAPTURE);
            foreach ($boundaries[0] as [$separator, $offset]) {
                if (AssistantOperationalReaderRegistry::hasProjectNameContinuation(substr($name, $offset + strlen($separator)))) {
                    $name = rtrim(substr($name, 0, $offset));
                    break;
                }
            }
            if (preg_match('/^(?:(?:id|№|#)\s*)?[1-9]\d*(?=\s+(?:за|с|по|на|для|от)\b|[.!?]*$)/iu', $name, $numeric) === 1) {
                $name = $numeric[0];
            }
        }
        if (! $quoted && $name !== null && AssistantRequestProjectScope::fromPlan($plan)->requested
            && preg_match('/^(?:открыт|закрыт|просроченн|незаверш[её]нн|действующ|активн|планов|неподписанн|подписанн|несогласованн|согласованн|неоплаченн)[а-яё]*\s+(?:нарушен|замечан|задач|работ|договор|сч[её]т|комплект|изменен|запис|журнал|CRM-сделк)[а-яё]*(?![\pL\pN])/iu', $name) === 1) {
            return null;
        }

        return $name === '' || (! $quoted && $name !== null && preg_match('/^(?:есть|нет|ещ[её]|уже|сейчас|остал[а-яё]*|задержива[а-яё]*|просрочены|готовы|назначены|приняты|закрыты|завершены|ждут|имеют|действуют|что|кто|где|когда|как|какие|какой|какая|какое|сколько|нужно|надо|можно|не|и|или|но|а)(?![\pL\pN])/iu', $name) === 1) ? null : $name;
    }

    private static function hasImplicitProjectClause(string $query): bool
    {
        $candidate = AssistantReportEntityQuery::extract($query, 'project', preservePrepositions: true);
        $periodHead = $candidate === null ? null : AssistantProductionSchedulePeriod::withoutSuffix('текущий '.$candidate);
        if ($candidate !== null && (preg_match('/^(?:сегодня|вчера|позавчера)[.!?]*$/iu', $candidate) === 1
            || preg_match('/^с\s+график[а-яё]*(?:\s|[.!?;:,]|$)/iu', $candidate) === 1
            || preg_match('/^(?:без|с|со)\s+(?:(?:назначенн|указанн)[а-яё]*\s+)?(?:ответственн|исполнител)[а-яё]*(?=\s|[.!?]|$)/iu', $candidate) === 1
            || preg_match('/^(?:онлайн|офлайн|оффлайн|online|offline|(?:не\s+)?в\s+сети|вне\s+сети)(?=\s|[.!?]|$)/iu', $candidate) === 1
            || ($periodHead !== null && preg_match('/^текущий(?:\s+(?:за|с|со|по|для|на|от|в|во|до))*$/iu', $periodHead) === 1))) {
            return true;
        }

        $head = preg_split('/\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])/iu', $query, 2)[0] ?? '';

        return $candidate !== null
            && (preg_match('/\b(?:какие|какой|какая|какое|каков[а-яё]*|кто|что|где|когда|сколько|почему)(?![\pL\pN])/iu', $head) === 1 || preg_match('/\?\s*$/u', $query) === 1)
            && preg_match('/^\p{Ll}+(?:(?:ют|ут|ят|ат|ет|ёт|ит)(?:ся)?(?:\s+\p{Ll}|[.!?]*$)|(?:ованы|овано|ированы|ировано|ены|ено|аны|ано|ны|на|но|ен|ён)(?=\s|[.!?]|$))/u', $candidate) === 1;
    }

    public static function clarifiedQuery(string $query, string $name): ?string
    {
        $replacement = null;
        foreach ([['«', '»'], ['“', '”'], ['„', '“'], ['"', '"']] as [$open, $close]) {
            if (! str_contains($name, $open) && ! str_contains($name, $close)) {
                $replacement = $open.$name.$close;
                break;
            }
        }
        if ($replacement === null) {
            return null;
        }
        preg_match_all('/\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+)/iu', $query, $matches, PREG_OFFSET_CAPTURE);
        preg_match_all('/«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*"/u', $query, $quotes, PREG_OFFSET_CAPTURE);
        $mentions = array_values(array_filter($matches[0], static function (array $mention) use ($quotes): bool {
            foreach ($quotes[0] as [$quote, $offset]) {
                if ($mention[1] > $offset && $mention[1] < $offset + strlen($quote)) {
                    return false;
                }
            }

            return true;
        }));
        $spans = [];
        foreach ($mentions as $index => [$prefix, $offset]) {
            $slice = substr($query, $offset, isset($mentions[$index + 1]) ? $mentions[$index + 1][1] - $offset : null);
            $tail = substr($slice, strlen($prefix));
            if (preg_match('/^(?:«[^»]+»|“[^”]+”|„[^“]+“|"[^"]+")/u', $tail, $quoted) === 1) {
                $spans[] = [$offset + strlen($prefix), strlen($quoted[0])];

                continue;
            }
            $old = self::name(['request' => ['message' => $slice]]);
            $old = $old === null ? null : self::withoutScheduleComparison(AssistantProductionSchedulePeriod::withoutSuffix($old));
            $old = $old === null ? null : preg_replace('/\s+(?:и|а|но)$/iu', '', $old);
            $start = $old === null || $old === '' ? false : strpos($slice, $old, strlen($prefix));
            if ($start !== false) {
                $spans[] = [$offset + $start, strlen($old)];
            }
        }
        if ($spans === []) {
            return null;
        }
        foreach (array_reverse($spans) as [$offset, $length]) {
            $query = substr_replace($query, $replacement, $offset, $length);
        }

        return $query;
    }

    public static function withoutQuotedNames(string $query): string
    {
        return preg_replace('/(\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+))(?:«[^»]+»|“[^”]+”|„[^“]+“|"[^"]+")/iu', '$1текущий', $query) ?? $query;
    }

    public static function periodQuery(array $plan): string
    {
        $query = (string) ($plan['request']['message'] ?? '');
        $masked = self::withoutQuotedNames($query);
        if ($masked !== $query) {
            return $masked;
        }
        $scope = $plan['named_project_scope'] ?? null;
        $name = is_array($scope) && ($scope['message_sha256'] ?? null) === hash('sha256', $query) ? ($scope['name_query'] ?? null) : null;

        return is_string($name) && $name !== ''
            ? (preg_replace('/(\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+))'.preg_quote($name, '/').'/iu', '$1текущий', $query, 1) ?? $query) : $query;
    }

    public static function semanticQuery(string $query): string
    {
        $withoutQuotes = self::withoutQuotedNames($query);
        if ($withoutQuotes !== $query) {
            return $withoutQuotes;
        }
        $name = self::name(['request' => ['message' => $query]]);
        $name = $name === null ? null : self::withoutScheduleComparison($name);

        return $name === null ? $query : (preg_replace('/(\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+))'.preg_quote($name, '/').'/iu', '$1текущий', $query, 1) ?? $query);
    }

    public static function planningQuery(string $query): string
    {
        $intent = AssistantOperationalReaderRegistry::intent($query);

        return isset(AssistantOperationalReaderRegistry::TOOLS[$intent['name'] ?? '']) ? self::semanticQuery($query) : $query;
    }

    private static function withoutScheduleComparison(string $name): string
    {
        $parts = preg_split('/\s+с\s+(?:(?:(?:текущим|действующим|активным)\s+)?графиком|плановым(?:и)?\s+срок(?:ом|ами))(?![\pL\pN])/iu', $name, 2);

        return trim((string) ($parts[0] ?? $name));
    }

    private static function hasQuotedName(string $query): bool
    {
        return preg_match('/(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+)[«“„"]/iu', $query) === 1;
    }
}
