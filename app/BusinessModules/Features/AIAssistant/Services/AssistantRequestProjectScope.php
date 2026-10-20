<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;

final readonly class AssistantRequestProjectScope
{
    private const CURRENT_PROJECT = '(?<![\pL\pN])(?:(?:текущ|выбран|эт|данн)[а-яё]*|наш(?:его|ему|ем|им)?)\s+(?:проект|объект)[а-яё]*(?![\pL\pN])';

    private const QUOTED_NAME = '«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*"';

    private const NUMERIC_PROJECTS = '(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*([1-9]\d*(?:\s*(?:,|и|или)\s*(?:(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*)?[1-9]\d*)*)(?![\pL\pN])';

    public function __construct(public bool $requested, public ?int $projectId, private ?int $contextProjectId = null) {}

    public static function fromPlan(array $plan): self
    {
        $question = (string) ($plan['request']['message'] ?? '');
        if (array_key_exists('named_project_scope', $plan)) {
            $named = $plan['named_project_scope'];
            $valid = is_array($named) && is_string($named['message_sha256'] ?? null)
                && hash_equals(hash('sha256', $question), $named['message_sha256']);

            return new self(true, $valid ? self::positiveId($named['project_id'] ?? null) : null);
        }
        $organizationWide = self::organizationWide($question);
        $requested = ! $organizationWide && preg_match('/'.self::CURRENT_PROJECT.'/iu', $question) === 1;
        $context = is_array($plan['request']['context'] ?? null) ? $plan['request']['context'] : [];
        $projects = [];
        foreach (['entity_refs', 'entity_references'] as $key) {
            foreach (is_array($context[$key] ?? null) ? $context[$key] : [] as $reference) {
                if (! is_array($reference) || ($reference['entity_type'] ?? $reference['type'] ?? null) !== 'project') {
                    continue;
                }
                $id = self::positiveId($reference['entity_id'] ?? $reference['id'] ?? null);
                if ($id !== null) {
                    $projects[$id] = true;
                }
            }
        }

        $contextProjectId = count($projects) === 1 ? (int) array_key_first($projects) : null;

        return new self($requested, $organizationWide ? null : $contextProjectId, $contextProjectId);
    }

    public static function organizationWide(string $question): bool
    {
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $projects = '(?:(?:во?\s+)?всех\s+(?:наших\s+)?(?:проект|объект)|по\s+всем\s+(?:нашим\s+)?(?:проект|объект)|люб[а-яё]*\s+(?:из\s+(?:наших|всех)|наш[а-яё]*)\s+(?:проект|объект))';
        $records = '(?:акт(?:ы|ам|ов)|договор(?:ы|ам|ов)|журнал(?:ы|ам|ов)|сотрудник(?:и|ам|ов)|пользовател(?:и|ям|ей)|плат[её]ж(?:и|ам|ей)|смет(?:ы|ам)?|задач(?:и|ам)?|работ(?:ы|ам)?|документ(?:ы|ам|ов)|тендер(?:ы|ам|ов)|заяв(?:ки|кам|ок)|закуп(?:ки|кам|ок)|материал(?:ы|ам|ов)|поставщик(?:и|ам|ов)|контрагент(?:ы|ам|ов)|склад(?:ы|ам|ов)|бригад(?:ы|ам)?'
            .'|дефект(?:ы|ам|ов)|замечани(?:я|ям|й)|несоответстви(?:я|ям|й)|инцидент(?:ы|ам|ов)|происшестви(?:я|ям|й)|нарушени(?:я|ям|й)|инструктаж(?:и|ам|ей)|проверк(?:и|ам)?|инспекци(?:и|ям|й)'
            .'|наряд(?:ы|ам|ов)(?:-допуск(?:и|ам|ов))?|табел(?:и|ям|ей)|трудозатрат(?:ы|ам)?|начислени(?:я|ям|й)|выработк(?:и|ам)?|запис(?:и|ям|ей)(?:\s+(?:времени|табеля|выработки))?'
            .'|сдел(?:ки|кам|ок)|лид(?:ы|ам|ов)|компани(?:и|ям|й)|контакт(?:ы|ам|ов)|обращени(?:я|ям|й)|активност(?:и|ям|ей)|(?:коммерческ[а-яё]*\s+)?предложени(?:я|ям|й)|стать(?:и|ям|ей)(?:\s+базы\s+знаний)?'
            .'|остат(?:ки|кам|ков)|баланс(?:ы|ам|ов)|движени(?:я|ям|й)(?:\s+материалов)?|распределени(?:я|ям|й)|резерв(?:ы|ам|ов)|ячей(?:ки|кам|ек)(?:\s+хранения)?|актив(?:ы|ам|ов)|достав(?:ки|кам|ок)|постав(?:ки|кам|ок)|заказ(?:ы|ам|ов)|при[её]м(?:ки|кам|ок)|согласовани(?:я|ям|й)'
            .'|график(?:и|ам|ов)|расписани(?:я|ям|й)|комплект(?:ы|ам|ов)(?:\s+документации)?|пакет(?:ы|ам|ов)(?:\s+документации)?|артефакт(?:ы|ам|ов)|верси(?:и|ям|й)|набор(?:ы|ам|ов)(?:\s+моделей)?'
            .'|машин(?:ы|ам)?|механизм(?:ы|ам|ов)|назначени(?:я|ям|й)|просто(?:и|ям|ев)|сменн[а-яё]*\s+отч[её]т(?:ы|ам|ов)|ремонт(?:ы|ам|ов)|выдач(?:и|ам)?(?:\s+топлива)?'
            .'|запрос(?:ы|ам|ов)(?:\s+(?:изменений|информации))?|изменени(?:я|ям|й)|претензи(?:и|ям|й)|чек-лист(?:ы|ам|ов)|пункт(?:ы|ам|ов)(?:\s+чек-листов)?|сесси(?:и|ям|й)(?:\s+при[её]мки)?|корректирующ[а-яё]*\s+мероприяти(?:я|ям|й))';
        $organization = '(?:всей\s+(?:нашей\s+)?организац|в\s+целом\s+по\s+(?:нашей\s+)?организац|(?:по\s+всем|всех|все)\s+(?:наш(?:и|им|их)\s+)?'.$records.'\s+(?:нашей\s+)?организации(?![\pL\pN]))';
        $scope = '(?:'.$projects.'|'.$organization.')';

        return (preg_match('/(?<![\pL\pN])'.$projects.'/iu', $question) === 1
            || (preg_match('/(?<![\pL\pN])'.$organization.'/iu', $question) === 1
                && (! self::hasAffirmativeCurrentProject($question) || self::comparesCurrentProject($question))))
            && preg_match('/(?<![\pL\pN])(?:не\s+(?:(?:надо|нужно|следует|стоит)\s+)?(?:(?:показыва|покажи|включ|учитыва|учти|добав|бери|брать|ищи|искать|работа)[а-яё]*\s+|(?:выполня|проводи)[а-яё]*\s+поиск\s+)?|(?:кроме|исключая)\s+)(?:(?:в|во|на|по|для|из|с|со)\s+)?'.$scope.'/iu', $question) !== 1;
    }

    public static function bindSearchScope(string $question, array $arguments, ?int $contextProjectId, bool $projectFilterSupported, ?callable $search = null): array
    {
        if (self::organizationWide($question)) {
            $id = self::positiveId($arguments['project_id'] ?? null);
            if ($id !== null && self::hasExplicitProjectSections($question)) {
                $scope = new self(false, null, $contextProjectId);
                if (! in_array($id, $scope->reportProjectIds($question, $search), true)) {
                    throw new AssistantReportScopeAmbiguous;
                }
            } else {
                $arguments['project_id'] = null;
            }
        } elseif (! array_key_exists('project_id', $arguments) && $projectFilterSupported) {
            $arguments['project_id'] = $contextProjectId;
        }

        return $arguments;
    }

    public function bindReportScope(string $question, array $arguments, ?callable $search = null): array
    {
        $organizationWide = ! $this->requested && self::organizationWide($question);
        if ((! $organizationWide && (! $this->requested || $this->projectId === null))
            || ! is_array($arguments['sections'] ?? null)) {
            return $arguments;
        }
        $mixed = $organizationWide && self::hasExplicitProjectSections($question);
        $projectIds = null;
        $boundProjects = [];
        $projectSections = [];
        $hasOrganizationSection = false;
        foreach ($arguments['sections'] as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $scope = $section['scope'] ?? null;
            if (($scope !== null && ! in_array($scope, ['organization', 'project'], true))
                || ($mixed && $scope === null) || (! $organizationWide && $scope === 'organization')) {
                throw new AssistantReportScopeAmbiguous;
            }
            $hasOrganizationSection = $hasOrganizationSection || $scope === 'organization';
            if ($organizationWide && $scope === 'project') {
                if (! $mixed) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $projectIds ??= $this->reportProjectIds($question, $search);
                $id = self::positiveId($section['project_id'] ?? null);
                if (! in_array($id, $projectIds, true)) {
                    if (count($projectIds) !== 1 || ($id !== null && $id !== $this->contextProjectId)) {
                        throw new AssistantReportScopeAmbiguous;
                    }
                    $id = $projectIds[0];
                }
                $this->assertProjectFilters($section, $id);
                $signature = ['project_id' => $id];
                foreach (['domain', 'entity_type', 'columns', 'filters', 'group_by', 'metrics', 'order_by', 'row_limit'] as $key) {
                    $signature[$key] = $section[$key] ?? null;
                }
                if (in_array($signature, $projectSections, true)) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $projectSections[] = $signature;
                $boundProjects[$id] = true;
                $arguments['sections'][$index]['project_id'] = $id;

                continue;
            }
            if ($organizationWide
                && self::positiveId($section['project_id'] ?? null) !== null
                && self::positiveId($section['project_id']) !== $this->contextProjectId) {
                throw new AssistantReportScopeAmbiguous;
            }
            if (! $organizationWide || $scope === 'organization'
                || ($this->contextProjectId !== null && self::positiveId($section['project_id'] ?? null) === $this->contextProjectId)) {
                $arguments['sections'][$index]['project_id'] = $organizationWide ? null : $this->projectId;
            }
            if ($organizationWide && is_array($section['filters'] ?? null)) {
                $arguments['sections'][$index]['filters'] = array_values(array_filter($section['filters'],
                    fn (mixed $filter): bool => ! is_array($filter) || ! $this->isSelectedProjectFilter($filter)));
                foreach ($arguments['sections'][$index]['filters'] as $filter) {
                    if (is_array($filter) && ($filter['field'] ?? null) === 'project_id'
                        && ! in_array($filter['operator'] ?? null, ['neq', 'is_null', 'is_not_null'], true)) {
                        throw new AssistantReportScopeAmbiguous;
                    }
                }
            }
        }
        if ($mixed) {
            $projectIds ??= $this->reportProjectIds($question, $search);
            if (! $hasOrganizationSection || array_diff($projectIds, array_keys($boundProjects)) !== []) {
                throw new AssistantReportScopeAmbiguous;
            }
        }

        return $arguments;
    }

    public function comparisonProjectIds(string $question, ?callable $search = null): ?array
    {
        return ! $this->requested && self::organizationWide($question) && self::hasExplicitProjectSections($question)
            ? $this->reportProjectIds($question, $search) : null;
    }

    private static function hasAffirmativeCurrentProject(string $question): bool
    {
        return preg_match('/'.self::CURRENT_PROJECT.'/iu', self::withoutNegativeCurrentProject($question)) === 1;
    }

    private static function withoutNegativeCurrentProject(string $question): string
    {
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $negative = '(?<![\pL\pN])(?:не\s+(?:(?:огранич[а-яё]*|только)\s+(?:[\pL-]+\s+){0,3})?|без\s+(?:ограничени[а-яё]*\s+(?:[\pL-]+\s+){0,3})?|(?:кроме|исключая|за\s+исключением)\s+)';

        return preg_replace('/'.$negative.'(?:(?:в|во|на|по|для|из|с|со)\s+)?'.self::CURRENT_PROJECT.'/iu', ' ', $question) ?? $question;
    }

    private static function comparesCurrentProject(string $question): bool
    {
        $question = self::withoutNegativeCurrentProject($question);

        return preg_match('/(?<![\pL\pN])(?:сравн|сопостав)[а-яё]*(?!\pL)/iu', $question) === 1
            && (preg_match('/(?<![\pL\pN])(?:с|со|и|против|относительно)\s+'.self::CURRENT_PROJECT.'/iu', $question) === 1
                || preg_match('/'.self::CURRENT_PROJECT.'\s+(?:и|с|со|против|относительно)\s+(?:(?:в|во|на|по)\s+)?(?:всех|всем|все|всей)(?![\pL\pN])/iu', $question) === 1);
    }

    private static function hasExplicitProjectSections(string $question): bool
    {
        $question = self::withoutExcludedProjects($question);
        $quotedProject = preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*:?\s*[«„“"]/iu', $question) === 1;
        $question = self::withoutNegativeCurrentProject($question);

        return $quotedProject || self::comparesCurrentProject($question)
            || preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*[1-9]\d*(?![\pL\pN])/iu', $question) === 1
            || AssistantReportEntityQuery::extract($question, 'project') !== null
            || AssistantReportEntityQuery::hasAmbiguousName($question, 'project');
    }

    private function reportProjectIds(string $question, ?callable $search): array
    {
        $question = self::withoutExcludedProjects($question);
        $ids = [];
        if (self::hasAffirmativeCurrentProject($question) && $this->contextProjectId !== null) {
            $ids[] = $this->contextProjectId;
        }
        $unquoted = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        preg_match_all('/'.self::NUMERIC_PROJECTS.'/iu', $unquoted, $groups);
        foreach ($groups[1] as $group) {
            preg_match_all('/[1-9]\d*/', $group, $numbers);
            foreach ($numbers[0] as $number) {
                $id = self::positiveId($number);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }
        $nameQuestion = preg_replace('/(?:'.self::QUOTED_NAME.')(*SKIP)(*F)|(?:'.self::CURRENT_PROJECT.'|'.self::NUMERIC_PROJECTS.')/iu', ' ', $question) ?? $question;
        if (AssistantReportEntityQuery::hasAmbiguousName($nameQuestion, 'project', preservePrepositions: true)) {
            throw new AssistantReportScopeAmbiguous;
        }
        $name = AssistantReportEntityQuery::extract($nameQuestion, 'project', preservePrepositions: true);
        if ($name === null) {
            if ($ids !== [] && preg_match('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])/iu', $nameQuestion) !== 1) {
                return array_values(array_unique($ids));
            }
            throw new AssistantReportScopeAmbiguous;
        }
        if ($search === null) {
            throw new AssistantReportScopeAmbiguous;
        }
        $quoted = preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*:?\s*(?:'.self::QUOTED_NAME.')/iu', $nameQuestion) === 1;
        foreach (self::reportNameCandidates($name, $quoted) as $candidate) {
            $result = $search(['query' => $candidate, 'name_match' => 'exact', 'limit' => 2]);
            $rows = is_array($result) ? ($result['results'] ?? null) : null;
            if (! is_array($result) || ($result['status'] ?? null) !== 'success' || ! is_array($rows)
                || ($result['result_window']['has_more'] ?? null) !== false) {
                throw new AssistantReportScopeAmbiguous;
            }
            if ($rows === []) {
                continue;
            }
            if (count($rows) !== 1 || ! is_int($rows[0]['id'] ?? null) || $rows[0]['id'] < 1) {
                throw new AssistantReportScopeAmbiguous;
            }

            return array_values(array_unique([...$ids, $rows[0]['id']]));
        }

        throw new AssistantReportScopeAmbiguous;
    }

    private static function reportNameCandidates(string $name, bool $quoted): array
    {
        $candidates = [$name];
        if ($quoted) {
            return $candidates;
        }
        $group = '(?:статусам|валютам|проектам|объектам|подрядчикам|контрагентам|месяцам|неделям|дням)';
        $withoutGrouping = preg_replace('/\s+(?:по|с\s+группировкой\s+по)\s+'.$group.'(?:\s*(?:,|и)\s*'.$group.')*\s*[.!?]*$/iu', '', $name) ?? $name;
        $candidates[] = $withoutGrouping;
        $candidates[] = AssistantProductionSchedulePeriod::withoutCompleteSuffix($withoutGrouping);

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
    }

    private static function withoutExcludedProjects(string $question): string
    {
        $negative = '(?<![\pL\pN])(?:без|кроме|исключая|за\s+исключением)\s+';
        $noun = '(?<![\pL\pN])(?:проект|объект)[а-яё]*(?![\pL\pN])';
        $stop = '(?:\s+(?:с|со|против|относительно)\s+(?:'.self::CURRENT_PROJECT.'|'.$noun.')|[,;.!?\r\n]|$)';
        $name = '(?:(?!'.$stop.')[^,;.!?\r\n])+';
        $excluded = '(?:'.self::CURRENT_PROJECT.'|'.self::NUMERIC_PROJECTS.'|'.$noun.'\s*:?\s*(?:'.self::QUOTED_NAME.')|'.$noun.'(?:\s+'.$name.')?)';

        return preg_replace('/(?:'.self::QUOTED_NAME.')(*SKIP)(*F)|'.$negative.$excluded.'/iu', ' ', $question) ?? $question;
    }

    private function assertProjectFilters(array $section, int $projectId): void
    {
        foreach (is_array($section['filters'] ?? null) ? $section['filters'] : [] as $filter) {
            if (! is_array($filter) || ($filter['field'] ?? null) !== 'project_id') {
                continue;
            }
            $value = $filter['value'] ?? null;
            $conflicts = match ($filter['operator'] ?? null) {
                'eq' => self::positiveId($value) !== $projectId,
                'neq' => self::positiveId($value) === $projectId,
                'in' => ! is_array($value) || ! in_array($projectId, array_map(self::positiveId(...), $value), true),
                'is_null' => true,
                default => false,
            };
            if ($conflicts) {
                throw new AssistantReportScopeAmbiguous;
            }
        }
    }

    private function isSelectedProjectFilter(array $filter): bool
    {
        if ($this->contextProjectId === null || ($filter['field'] ?? null) !== 'project_id') {
            return false;
        }
        $values = match ($filter['operator'] ?? null) {
            'eq' => [$filter['value'] ?? null],
            'in' => is_array($filter['value'] ?? null) ? $filter['value'] : [],
            default => [],
        };

        foreach ($values as $value) {
            if (self::positiveId($value) !== $this->contextProjectId) {
                return false;
            }
        }

        return $values !== [];
    }

    public function allowsReferences(array $references, array $results = []): bool
    {
        if (! $this->requested) {
            return true;
        }
        if ($this->projectId === null) {
            return false;
        }
        $contractors = $this->linkedContractors($results);
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                return false;
            }
            if (self::positiveId($reference['project_id'] ?? null) === $this->projectId) {
                continue;
            }
            $type = $reference['entity_type'] ?? null;
            $id = self::positiveId($reference['entity_id'] ?? null);
            if ($type === 'project' && $id === $this->projectId) {
                continue;
            }
            if ($type === 'live_project_financial_projection' && ($reference['project_id'] ?? null) === null
                && ($reference['projection_name'] ?? null) === $type && $id === $this->projectId
                && self::positiveId($reference['composite_key']['project_id'] ?? null) === $this->projectId) {
                continue;
            }
            if ($type === 'contractor' && $id !== null && isset($contractors[$id])) {
                continue;
            }

            return false;
        }

        return true;
    }

    public static function references(array $result): array
    {
        $references = is_array($result['source_refs'] ?? null) ? $result['source_refs'] : [];
        foreach (['structured_fact_evidence', 'financial_evidence'] as $key) {
            if (is_array($result[$key]['source_refs'] ?? null)) {
                $references = [...$references, ...$result[$key]['source_refs']];
            }
            foreach ($result[$key]['rows'] ?? [] as $row) {
                if (is_array($row['source_ref'] ?? null)) {
                    $references[] = $row['source_ref'];
                }
            }
        }
        if (is_array($result['domain_search_count_evidence']['source_ref'] ?? null)) {
            $references[] = $result['domain_search_count_evidence']['source_ref'];
        }
        if (is_array($result['rag_context']['sources'] ?? null)) {
            $references = [...$references, ...$result['rag_context']['sources']];
        }
        foreach (array_slice(is_array($result['live_entity_reads'] ?? null) ? $result['live_entity_reads'] : [], 0, AssistantStructuredFactFormatter::MAX_ROWS) as $read) {
            if (! is_array($read)) {
                continue;
            }
            unset($read['live_entity_reads']);
            $references = [...$references, ...self::references($read)];
        }

        return $references;
    }

    public function filterResults(array $results): array
    {
        return array_values(array_filter($results, fn (array $result): bool => $this->allowsReferences(self::references($result), $results)));
    }

    public function withAnchorReferences(array $references, array $results): array
    {
        if (! $this->requested) {
            return $references;
        }
        $contractors = $this->linkedContractors($results);
        foreach ($references as $reference) {
            if (($reference['entity_type'] ?? null) !== 'contractor') {
                continue;
            }
            $id = self::positiveId($reference['entity_id'] ?? null);
            if ($id !== null && isset($contractors[$id]) && ! in_array($contractors[$id], $references, true)) {
                $references[] = $contractors[$id];
            }
        }

        return $references;
    }

    private function linkedContractors(array $results): array
    {
        $contractors = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $reads = [$result, ...array_slice(is_array($result['live_entity_reads'] ?? null) ? $result['live_entity_reads'] : [], 0, AssistantStructuredFactFormatter::MAX_ROWS)];
            foreach ($reads as $read) {
                if (! is_array($read)) {
                    continue;
                }
                foreach ($read['results'] ?? [] as $row) {
                    if (($row['entity_type'] ?? null) !== 'contract') {
                        continue;
                    }
                    $contractorId = self::positiveId($row['fields']['contractor_id'] ?? null);
                    if ($contractorId === null) {
                        continue;
                    }
                    foreach ($read['source_refs'] ?? [] as $reference) {
                        if (($reference['entity_type'] ?? null) === 'contract'
                            && (string) ($reference['entity_id'] ?? '') === (string) ($row['id'] ?? '')
                            && self::positiveId($reference['project_id'] ?? null) === $this->projectId
                            && in_array('contractor_id', $reference['checked_fields'] ?? [], true)) {
                            $contractors[$contractorId] = $reference;
                        }
                    }
                }
            }
        }

        return $contractors;
    }

    private static function positiveId(mixed $id): ?int
    {
        return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0 ? (int) $id : null;
    }
}
