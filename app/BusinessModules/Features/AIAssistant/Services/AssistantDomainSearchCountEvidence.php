<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use Throwable;

final class AssistantDomainSearchCountEvidence
{
    public const TYPE = 'live_domain_search_count';

    private const ORGANIZATION_SCOPE = '/(?<!\pL)(?:в|во|по)\s+(?:(?:всей|текущей|выбранной|нашей|моей|этой)\s+)?организаци[а-яё]*/iu';

    public static function payload(User $actor, int $organizationId, AssistantDomainDefinition $definition, string $entityType, array $arguments, array $fields, int $total, string $fetchedAt): array
    {
        sort($fields);
        $scope = ['domain' => $definition->domain, 'entity_type' => $entityType,
            'query' => trim((string) ($arguments['query'] ?? '')),
            'project_id' => is_numeric($arguments['project_id'] ?? null) ? (int) $arguments['project_id'] : null, 'fields' => $fields];
        $clock = null;
        if (($arguments['predicate'] ?? null) === 'overdue') {
            if ($definition->domain !== 'schedule' || $entityType !== 'schedule_task') {
                throw new \LogicException('unsupported_count_predicate');
            }
            $scope['predicate'] = 'overdue';
            $clock = self::predicateClock();
        }
        $identity = self::sign([$organizationId, (string) $actor->getKey(), $scope, $clock]);
        $permissions = array_merge($definition->permissions, (array) ($definition->entityPermissions[$entityType] ?? []));
        foreach ($fields as $field) {
            $permissions = array_merge($permissions, (array) ($definition->fieldPermissions[$field] ?? []));
        }
        $reference = ['source_type' => self::TYPE, 'entity_type' => self::TYPE, 'entity_id' => 'c_'.substr($identity, 0, 32),
            'projection_name' => self::TYPE, 'organization_id' => $organizationId, 'project_id' => $scope['project_id'],
            'content_scope' => 'structured', 'checked_fields' => ['record_count'], 'record_count' => $total,
            'required_domains' => [match ($definition->domain) { 'works' => 'projects', 'acts' => 'contracts', default => $definition->domain }],
            'required_permissions' => array_values(array_unique($permissions)), 'source_version' => self::sign([$identity, $total]),
            'fetched_at' => $fetchedAt, '_search_count_query' => $scope, '_search_count_actor' => (string) $actor->getKey()];
        if ($clock !== null) {
            $reference['_search_count_clock'] = $clock;
        }
        $reference['_search_count_proof'] = self::sign($reference);

        return ['field' => 'record_count', 'value' => $total, 'source_ref' => $reference];
    }

    public static function trusted(array $evidence): bool
    {
        $reference = $evidence['source_ref'] ?? null;
        if (($evidence['field'] ?? null) !== 'record_count' || ! is_int($evidence['value'] ?? null) || $evidence['value'] < 0
            || ! is_array($reference) || $evidence['value'] !== ($reference['record_count'] ?? null)
            || ($reference['entity_type'] ?? null) !== self::TYPE || ($reference['source_type'] ?? null) !== self::TYPE
            || ($reference['projection_name'] ?? null) !== self::TYPE || ($reference['checked_fields'] ?? null) !== ['record_count']
            || ($reference['content_scope'] ?? null) !== 'structured' || ! is_array($reference['_search_count_query'] ?? null)
            || ! is_string($reference['_search_count_actor'] ?? null) || ! is_string($reference['_search_count_proof'] ?? null)
            || ! is_string($reference['fetched_at'] ?? null)) {
            return false;
        }
        if (($reference['_search_count_query']['predicate'] ?? null) === 'overdue'
            && (($reference['_search_count_query']['domain'] ?? null) !== 'schedule'
                || ($reference['_search_count_query']['entity_type'] ?? null) !== 'schedule_task'
                || ! self::matchesPredicateClock($reference['_search_count_clock'] ?? null))) {
            return false;
        }
        try {
            $age = time() - (new \DateTimeImmutable($reference['fetched_at']))->getTimestamp();
            $proof = $reference['_search_count_proof'];
            unset($reference['_search_count_proof']);

            return $age >= -30 && $age <= 600 && hash_equals(self::sign($reference), $proof);
        } catch (Throwable) {
            return false;
        }
    }

    public function matchesReference(User $actor, int $organizationId, array $reference): bool
    {
        if (! self::trusted(['field' => 'record_count', 'value' => $reference['record_count'] ?? null, 'source_ref' => $reference])
            || ($reference['organization_id'] ?? null) !== $organizationId
            || ($reference['_search_count_actor'] ?? null) !== (string) $actor->getKey()) {
            return false;
        }
        try {
            $current = app(AssistantDomainReadService::class)->execute('search', $reference['_search_count_query'] + ['limit' => 1], $actor, $organizationId);

            return self::matchesCurrentEvidence($reference, $current['domain_search_count_evidence'] ?? []);
        } catch (Throwable) {
            return false;
        }
    }

    public function canReadReference(User $actor, int $organizationId, array $reference): bool
    {
        return $this->matchesReference($actor, $organizationId, $reference);
    }

    public static function matchesCurrentEvidence(array $reference, array $current): bool
    {
        return self::trusted(['field' => 'record_count', 'value' => $reference['record_count'] ?? null, 'source_ref' => $reference])
            && self::trusted($current) && ($current['source_ref']['entity_id'] ?? null) === ($reference['entity_id'] ?? null)
            && ($current['source_ref']['source_version'] ?? null) === ($reference['source_version'] ?? null)
            && ($current['source_ref']['required_permissions'] ?? null) === ($reference['required_permissions'] ?? null);
    }

    public static function isCountRequest(string $query): bool
    {
        if (self::targetPhrase($query) === null || preg_match('/(?:сумм|стоимост|рубл|деньг|денег|денеж|объ[её]м|таблиц|список|перечисл|\b(?:RUB|USD|EUR)\b|стоят|стоит)/iu', $query) === 1
            || preg_match('/(?:сколько|количество|число)\s+(?:остал[а-яё]*|остат[а-яё]*|поставлен[а-яё]*|доставлен[а-яё]*|получен[а-яё]*|израсходован[а-яё]*)/iu', $query) === 1
            || preg_match('/(?<![\pL\pN])(?:кг|м[²³]|м2|м3|килограмм[а-яё]*|тонн[а-яё]*|литр[а-яё]*|метр[а-яё]*|кубометр[а-яё]*|куб[а-яё]*|цен[а-яё]*)(?![\pL\pN])/iu', $query) === 1) {
            return false;
        }

        return preg_match('/материал[а-яё]*|бетон[а-яё]*|топлив[а-яё]*/iu', $query) !== 1
            || preg_match('/(?:сколько|количество|число)\s+(?:(?:всего|доступн[а-яё]*|найденн[а-яё]*)\s+){0,2}запис[а-яё]*/iu', $query) === 1;
    }

    public static function matchesRequestedEntity(string $query, array $evidence): bool
    {
        $scope = $evidence['source_ref']['_search_count_query'] ?? [];
        if (self::isOverdueTaskRequest($query)) {
            return ($scope['domain'] ?? null) === 'schedule' && ($scope['entity_type'] ?? null) === 'schedule_task';
        }
        $type = $scope['entity_type'] ?? null;
        $target = self::targetPhrase($query);
        if (! is_string($type) || $target === null) { return false; }
        $catalog = app()->bound(AssistantDomainCatalog::class) ? app(AssistantDomainCatalog::class) : new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $definition = $catalog->definition((string) ($scope['domain'] ?? ''));
        if ($definition === null || ! in_array($type, $definition->entityTypes, true)) { return false; }
        $genericRecords = preg_match('/(?<!\pL)запис[а-яё]*(?!\pL)/iu', $target) === 1;
        $target = preg_replace('/(?<!\pL)(?:запис[а-яё]*|всего|доступн[а-яё]*|найденн[а-яё]*|найдено|зарегистрированн[а-яё]*)(?!\pL)/iu', ' ', $target) ?? $target;
        $targetWords = self::words($target);
        if ($targetWords === []) { return $genericRecords; }
        $registered = array_unique(array_merge(...array_map(static fn (AssistantDomainDefinition $entry): array => $entry->entityTypes, array_values($catalog->all()))));
        $explicitTypes = array_intersect($targetWords, $registered);
        if ($explicitTypes !== []) { return in_array($type, $explicitTypes, true); }
        $translated = app('translator')->get('ai_assistant_facts.entities');
        $labels = array_replace(is_array($translated) ? $translated : [], AssistantExtendedDomainRegistry::values('entityLabels'));
        $scores = [];
        $labelIdentities = [];
        $requestedStems = array_map(self::stem(...), $targetWords);
        foreach ($registered as $candidate) {
            if (! is_string($labels[$candidate] ?? null)) { continue; }
            $labelWords = array_unique(array_map(self::stem(...), self::words($labels[$candidate])));
            $overlap = count(array_intersect($labelWords, $requestedStems));
            if ($overlap > 0) {
                $scores[$candidate] = $overlap * 100 - count($labelWords);
                $labelIdentities[$candidate] = implode('|', $labelWords);
            }
        }

        if ($scores === [] || ! isset($scores[$type]) || $scores[$type] !== max($scores)) { return false; }
        $best = array_filter($scores, static fn (int $score): bool => $score === max($scores));

        return count(array_unique(array_intersect_key($labelIdentities, $best))) === 1;
    }

    public static function matchesRequestedScope(string $query, array $evidence, array $requestScope = []): bool
    {
        $scope = $evidence['source_ref']['_search_count_query'] ?? [];
        if (self::isOverdueTaskRequest($query)) {
            return self::matchesOverdueTaskScope($query, $evidence, $requestScope);
        }
        if (isset($scope['predicate'])) { return false; }
        $searchTerm = '';
        $semanticQuery = $query;
        $searchPattern = '/(?:по\s+(?:поиску|запросу)|со\s+словом|содержащ[а-яё]*\s+(?:слово|текст))\s+["«“]([^"»”]{1,200})["»”]/iu';
        if (preg_match_all($searchPattern, $query, $matches) > 0) {
            if (count($matches[1]) !== 1) { return false; }
            $searchTerm = trim($matches[1][0]);
            $semanticQuery = str_replace($matches[0][0], '', $query);
        }
        if (preg_match('/(?:статус|состояни|активн[а-яё]*|неактивн[а-яё]*|закрыт[а-яё]*|открыт[а-яё]*|заверш[её]н[а-яё]*|утвержд[её]н[а-яё]*|согласованн[а-яё]*|оплаченн[а-яё]*|неоплаченн[а-яё]*|просроч[а-яё]*|черновик|архивн[а-яё]*|отмен[её]н[а-яё]*|названи|названн|созданн|заключ[её]н|подписанн)/iu', $semanticQuery) === 1
            || preg_match('/(?<!\pL)(?:за\s+(?:\d|эт[а-яё]*|текущ[а-яё]*|прошл[а-яё]*|последн[а-яё]*|год|месяц|недел|день)|(?:до|после|между|начиная|с)\s+\d)/iu', $semanticQuery) === 1
            || preg_match('/["«“]/u', $semanticQuery) === 1) { return false; }
        $projectScoped = preg_match('/(?<!\pL)(?:в|на|по|для|из)\s+(?:(?:текущ[а-яё]*|выбранн[а-яё]*|этом|этого|наш[а-яё]*)\s+)?проект[а-яё]*(?!\pL)/iu', $semanticQuery, $projectMatch, PREG_OFFSET_CAPTURE) === 1;
        if ($projectScoped) {
            $suffix = trim(substr($semanticQuery, $projectMatch[0][1] + strlen($projectMatch[0][0])), " \t\n\r\0\x0B?.!,");
            if ($suffix !== '' && preg_match('/^(?:сколько|количество|число)(?!\pL)/iu', $suffix) !== 1) { return false; }
        }
        $organizationWide = self::isOrganizationWideRequest($query);
        if ($organizationWide && preg_match(self::ORGANIZATION_SCOPE, $semanticQuery, $organizationMatch, PREG_OFFSET_CAPTURE) === 1) {
            $suffix = trim(substr($semanticQuery, $organizationMatch[0][1] + strlen($organizationMatch[0][0])), " \t\n\r\0\x0B?.!,");
            if ($suffix !== '' && preg_match('/^(?:сколько|количество|число)(?!\pL)/iu', $suffix) !== 1) { return false; }
        }
        $expectedProject = $organizationWide ? null : ($requestScope['project_id'] ?? null);
        if ($expectedProject !== null && (! is_int($expectedProject) || $expectedProject < 1)) { return false; }
        if (($projectScoped && $expectedProject === null) || ($scope['project_id'] ?? null) !== $expectedProject) { return false; }
        if ($projectScoped) { $semanticQuery = str_replace($projectMatch[0][0], '', $semanticQuery); }
        if ($organizationWide && isset($organizationMatch[0][0])) { $semanticQuery = str_replace($organizationMatch[0][0], '', $semanticQuery); }
        if (! self::hasOnlyConfirmedTargetWords($semanticQuery, (string) ($scope['entity_type'] ?? ''))) { return false; }
        if (array_key_exists('query', $requestScope)) {
            if (! is_string($requestScope['query'])) { return false; }
            $contextSearch = trim($requestScope['query']);
            if ($searchTerm !== '' && $contextSearch !== '' && $searchTerm !== $contextSearch) { return false; }
            $searchTerm = $searchTerm !== '' ? $searchTerm : $contextSearch;
        }

        return mb_strtolower(trim((string) ($scope['query'] ?? ''), " \t\n\r\0\x0B\"«»“”")) === mb_strtolower($searchTerm);
    }

    public static function predicateClock(): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        return ['date' => \Carbon\CarbonImmutable::now($timezone)->toDateString(), 'timezone' => $timezone];
    }

    private static function matchesPredicateClock(mixed $clock): bool
    {
        $current = self::predicateClock();
        return is_array($clock) && count($clock) === 2 && ($clock['date'] ?? null) === $current['date']
            && ($clock['timezone'] ?? null) === $current['timezone'];
    }

    private static function isOverdueTaskRequest(string $query): bool
    {
        return self::isCountRequest($query) && preg_match('/(?<!\pL)просроч[а-яё]*\s+задач[а-яё]*(?!\pL)/iu', $query) === 1;
    }

    private static function matchesOverdueTaskScope(string $query, array $evidence, array $requestScope): bool
    {
        $scope = $evidence['source_ref']['_search_count_query'] ?? [];
        if (($scope['domain'] ?? null) !== 'schedule' || ($scope['entity_type'] ?? null) !== 'schedule_task'
            || ($scope['predicate'] ?? null) !== 'overdue' || ($scope['query'] ?? null) !== ''
            || ! self::matchesPredicateClock($evidence['source_ref']['_search_count_clock'] ?? null)) { return false; }
        $organizationWide = self::isOrganizationWideRequest($query);
        $expectedProject = $organizationWide ? null : ($requestScope['project_id'] ?? null);
        $projectScoped = preg_match('/(?<!\pL)(?:в|на|по|для|из)\s+(?:(?:текущ[а-яё]*|выбранн[а-яё]*|этом|этого|наш[а-яё]*)\s+)?(?:проект|объект)[а-яё]*(?!\pL)/iu', $query, $projectMatch) === 1;
        if ($expectedProject !== null && (! is_int($expectedProject) || $expectedProject < 1)) { return false; }
        if ($projectScoped && $expectedProject === null || ($scope['project_id'] ?? null) !== $expectedProject
            || isset($requestScope['query']) && $requestScope['query'] !== '') { return false; }
        $semantic = preg_replace('/(?<!\pL)просроч[а-яё]*(?!\pL)/iu', '', $query) ?? $query;
        if ($projectScoped) { $semantic = str_replace($projectMatch[0], '', $semantic); }
        if ($organizationWide) { $semantic = preg_replace(self::ORGANIZATION_SCOPE, '', $semantic) ?? $semantic; }
        $semantic = preg_replace('/(?<!\pL)их(?!\pL)/iu', '', $semantic) ?? $semantic;
        return self::hasOnlyConfirmedTargetWords($semantic, 'schedule_task');
    }

    private static function hasOnlyConfirmedTargetWords(string $query, string $entityType): bool
    {
        $translated = app('translator')->get('ai_assistant_facts.entities');
        $labels = array_replace(is_array($translated) ? $translated : [], AssistantExtendedDomainRegistry::values('entityLabels'));
        $allowed = array_merge(self::words(is_string($labels[$entityType] ?? null) ? $labels[$entityType] : ''), [$entityType],
            self::words('сколько количество число запись записи записей всего доступно доступных найдено найденных есть имеется насчитывается сейчас пожалуйста скажи скажите подскажи подскажите покажи укажи'));
        $allowed = array_map(self::stem(...), $allowed);
        preg_match_all('/[\pL\pN_]+/u', str_replace('ё', 'е', mb_strtolower($query)), $words);

        return array_diff(array_map(self::stem(...), $words[0]), $allowed) === [];
    }

    public static function isOrganizationWideRequest(string $query): bool
    {
        return preg_match(self::ORGANIZATION_SCOPE, $query) === 1;
    }

    private static function targetPhrase(string $query): ?string
    {
        if (preg_match('/(?<![\pL\pN])(?:сколько|количество|число)(?![\pL\pN])\s+([^?!.]+)/iu', $query, $matches) !== 1) {
            return null;
        }

        return preg_split('/(?<!\pL)(?:в|во|на|по|у|для|из|со?|от|при|среди)(?!\pL)/iu', $matches[1], 2)[0];
    }

    private static function words(string $text): array
    {
        preg_match_all('/[\pL_]+/u', str_replace('ё', 'е', mb_strtolower($text)), $matches);

        return $matches[0];
    }

    private static function stem(string $word): string
    {
        if (mb_strlen($word) < 4 || str_contains($word, '_')) { return $word; }

        return preg_replace('/(?:иями|ями|ами|иям|иях|ов|ей|ия|ие|ий|ые|ое|ая|ую|ой|ый|ом|ем|ах|ях|ам|ям|ых|их|а|я|ы|и|у|ю|е|о|ь)$/u', '', $word) ?? $word;
    }

    private static function sign(array $value): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \LogicException('search_count_signing_key_unavailable');
        }

        return hash_hmac('sha256', json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR), $key);
    }

    private static function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonicalize($item) : $item, $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
