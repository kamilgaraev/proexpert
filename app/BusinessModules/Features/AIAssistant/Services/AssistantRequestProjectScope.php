<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;

final readonly class AssistantRequestProjectScope
{
    private const CURRENT_PROJECT = '(?<![\pL\pN])(?:(?:текущ|выбран|эт|данн)[а-яё]*|наш(?:его|ему|ем|им)?)\s+(?:проект|объект)[а-яё]*(?![\pL\pN])';

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
        $records = '(?:акт(?:ы|ам|ов)|договор(?:ы|ам|ов)|журнал(?:ы|ам|ов)|сотрудник(?:и|ам|ов)|пользовател(?:и|ям|ей)|плат[её]ж(?:и|ам|ей)|смет(?:ы|ам)?|задач(?:и|ам)?|работ(?:ы|ам)?|документ(?:ы|ам|ов)|тендер(?:ы|ам|ов)|заяв(?:ки|кам|ок)|закуп(?:ки|кам|ок)|материал(?:ы|ам|ов)|поставщик(?:и|ам|ов)|контрагент(?:ы|ам|ов)|склад(?:ы|ам|ов)|бригад(?:ы|ам)?)';
        $organization = '(?:всей\s+(?:нашей\s+)?организац|в\s+целом\s+по\s+(?:нашей\s+)?организац|(?:по\s+всем|всех|все)\s+(?:наш(?:и|им|их)\s+)?'.$records.'\s+(?:нашей\s+)?организации(?![\pL\pN]))';
        $scope = '(?:'.$projects.'|'.$organization.')';

        return (preg_match('/(?<![\pL\pN])'.$projects.'/iu', $question) === 1
            || (preg_match('/(?<![\pL\pN])'.$organization.'/iu', $question) === 1
                && (! self::hasAffirmativeCurrentProject($question) || self::comparesCurrentProject($question))))
            && preg_match('/(?<![\pL\pN])(?:не(?!\s+(?:только|забуд[а-яё]*|забыва[а-яё]*|прекраща[а-яё]*|перестава[а-яё]*|упуска[а-яё]*)(?!\pL))|кроме|исключая)\s+(?:[\pL\pN-]+\s+){0,4}(?:во?\s+)?'.$scope.'/iu', $question) !== 1;
    }

    public static function bindSearchScope(string $question, array $arguments, ?int $contextProjectId, bool $projectFilterSupported): array
    {
        if (self::organizationWide($question)) {
            $arguments['project_id'] = null;
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
        foreach ($arguments['sections'] as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $scope = $section['scope'] ?? null;
            if (($scope !== null && ! in_array($scope, ['organization', 'project'], true))
                || ($mixed && $scope === null) || (! $organizationWide && $scope === 'organization')) {
                throw new AssistantReportScopeAmbiguous;
            }
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
            if ($organizationWide && $scope === 'organization'
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
                if ($scope === 'organization') {
                    foreach ($arguments['sections'][$index]['filters'] as $filter) {
                        if (is_array($filter) && ($filter['field'] ?? null) === 'project_id'
                            && ! in_array($filter['operator'] ?? null, ['neq', 'is_null', 'is_not_null'], true)) {
                            throw new AssistantReportScopeAmbiguous;
                        }
                    }
                }
            }
        }
        if ($mixed) {
            $projectIds ??= $this->reportProjectIds($question, $search);
            if (array_diff($projectIds, array_keys($boundProjects)) !== []) {
                throw new AssistantReportScopeAmbiguous;
            }
        }

        return $arguments;
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
            && preg_match('/(?<![\pL\pN])(?:с|со|и|против|относительно)\s+'.self::CURRENT_PROJECT.'/iu', $question) === 1;
    }

    private static function hasExplicitProjectSections(string $question): bool
    {
        if (preg_match('/(?<![\pL\pN])(?:без|кроме|исключая|за\s+исключением)\s+(?:[\pL-]+\s+){0,3}(?:проект|объект)[а-яё]*(?!\pL)/iu', $question) === 1) {
            return false;
        }

        $quotedProject = preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*:?\s*[«„“"]/iu', $question) === 1;
        $question = self::withoutNegativeCurrentProject($question);

        return $quotedProject || self::comparesCurrentProject($question)
            || preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*[1-9]\d*(?![\pL\pN])/iu', $question) === 1
            || AssistantReportEntityQuery::extract($question, 'project') !== null
            || AssistantReportEntityQuery::hasAmbiguousName($question, 'project');
    }

    private function reportProjectIds(string $question, ?callable $search): array
    {
        $ids = [];
        if (self::hasAffirmativeCurrentProject($question) && $this->contextProjectId !== null) {
            $ids[] = $this->contextProjectId;
        }
        $unquoted = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $numericProjects = '(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*([1-9]\d*(?:\s*(?:,|и|или)\s*(?:(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*)?[1-9]\d*)*)(?![\pL\pN])';
        preg_match_all('/'.$numericProjects.'/iu', $unquoted, $groups);
        foreach ($groups[1] as $group) {
            preg_match_all('/[1-9]\d*/', $group, $numbers);
            foreach ($numbers[0] as $number) {
                $id = self::positiveId($number);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }
        $quotes = '«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*"';
        $nameQuestion = preg_replace('/(?:'.$quotes.')(*SKIP)(*F)|(?:'.self::CURRENT_PROJECT.'|'.$numericProjects.')/iu', ' ', $question) ?? $question;
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
        $result = $search(['query' => $name, 'name_match' => 'exact', 'limit' => 2]);
        $rows = is_array($result) ? ($result['results'] ?? null) : null;
        if (! is_array($result) || ($result['status'] ?? null) !== 'success' || ! is_array($rows) || count($rows) !== 1
            || ($result['result_window']['has_more'] ?? null) !== false || ! is_int($rows[0]['id'] ?? null) || $rows[0]['id'] < 1) {
            throw new AssistantReportScopeAmbiguous;
        }

        return array_values(array_unique([...$ids, $rows[0]['id']]));
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
