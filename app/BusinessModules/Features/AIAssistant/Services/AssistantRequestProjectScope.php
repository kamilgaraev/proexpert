<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComparisonSpecification;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;

final readonly class AssistantRequestProjectScope
{
    public const CURRENT_PROJECT = '(?<![\pL\pN])(?:(?:текущ|выбран|эт|данн)[а-яё]*|наш(?:его|ему|ем|им)?)\s+(?:проект|объект)[а-яё]*(?![\pL\pN])';

    private const QUOTED_NAME = '«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*"';

    private const ORGANIZATION_QUALIFIER = '(?:всей\s+(?:нашей\s+)?|в\s+целом\s+по\s+(?:нашей\s+)?)';

    private const CONDITION_STARTER = '(?:дороже|дешевле|больше|меньше|выше|ниже|свыше|равно)';

    private const NUMERIC_PROJECTS = '(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*([1-9]\d*(?:\s*(?:,|и|или)\s*(?:(?:проект|объект)[а-яё]*\s*)?(?:№|#|id)?\s*[1-9]\d*)*)(?![\pL\pN])';

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
        return self::organizationScope($question, true);
    }

    private static function organizationScope(string $question, bool $applyProjectRestriction): bool
    {
        $question = AssistantOrganizationRecordVocabulary::normalize($question, maskProjectNames: true);
        $originalQuestion = $question;
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $definitions = '(?:(?:[а-яё][а-яё-]*(?:ые|ие|ых|их|ым|им|ыми|ими)|частично|полностью|еще|ещё|пока|не)\s+){0,6}';
        $collectiveDefinitions = '(?:(?:[а-яё][а-яё-]*(?:ая|яя|ую|юю|ой|ей|ое|ее|ого|его|ому|ему|ым|им|ом|ем)|частично|полностью|еще|ещё|пока|не)\s+){0,6}';
        $collective = '(?:вся|всю|всей|вс[её]|весь|всего|всему|всем)\s+(?:наш(?:а|у|ей|е|его|ему|им|ем)?\s+)?'.$collectiveDefinitions
            .'(?:техник(?:а|у|и|е|ой)|оборудовани(?:е|я|ю|ем|и)|персонал(?:а|у|ом|е)?|выработк(?:а|у|и|е|ой)|рабоч(?:ее|его|ему|им|ем)\s+врем(?:я|ени|енем)|документаци(?:я|ю|и|ей)|качеств(?:о|а|у|ом|е)|безопасност(?:ь|и|ью))';
        $question = preg_replace('/(\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+))(?:все\s+(?:наши\s+)?'.$definitions.'(?:проекты|объекты)|'.$collective.'\s+(?:нашей\s+)?организации)(?![\pL\pN])/iu', '$1текущий', $question) ?? $question;
        $projects = '(?:(?:(?:с|со)\s+)?всеми\s+(?:нашими\s+)?(?:проект|объект)ами(?![\pL\pN])|(?:во?\s+)?всех\s+(?:наших\s+)?(?:проект|объект)(?:ах|ов)(?![\pL\pN])|по\s+всем\s+(?:нашим\s+)?(?:проект|объект)ам(?![\pL\pN])|люб[а-яё]*\s+(?:из\s+(?:наших|всех)|наш[а-яё]*)\s+(?:проект|объект)(?:ами|ах|ам|ов|ы|ом|а|у|е)?(?![\pL\pN])|все\s+(?:наши\s+)?(?:проекты|объекты)(?![\pL\pN]))';
        $records = self::recordNouns();
        $recordScope = '(?:(?:(?:с|со)\s+)?всеми|по\s+всем|всех|все)\s+(?:наш(?:ими|и|им|их)\s+)?'.$definitions.$records;
        $question = preg_replace('/'.self::CURRENT_PROJECT.'(*SKIP)(*F)|(\b(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+))'.$recordScope.'\s+(?:нашей\s+)?организации(?![\pL\pN])/iu', '$1текущий', $question) ?? $question;
        $command = '(?:(?:не|пожалуйста)\s+){0,2}(?:(?:сформируй|сгенерируй|создай|сделай|собери|подготовь|составь|сохрани|экспортируй|выгрузи|выведи|построй|объедини|сведи|подведи|покажи|показывай|посмотри|найди|посчитай|подсчитай|рассчитай|вычисли|сгруппируй|группируй|отсортируй|сортируй|сравни|сопоставь|сверь|проверь|проанализируй|оцени|объясни|расскажи|перечисли|дай|добавь|учти|учитывай|включи|включай|исключи|исключай)(?:те)?|ограничивайся|ограничивайтесь)';
        $postDefinition = '(?:(?:не|частично|полностью|еще|ещё|пока)\s+){0,3}[а-яё][а-яё-]*(?:ые|ие)';
        $continuation = '(?=\s*(?:$|[,.;:!?]|(?:в|во|на|по|за|с|со|против|относительно|без|для|из|от|до|после|перед|при|к|ко|о|об|и|или|а|но|вместе|кроме|исключая|включая|только|также|пожалуйста|сегодня|вчера|позавчера|завтра|сейчас|сверху|снизу|нужна|нужно|нужны|нужен|CSV|XLSX|PDF|Excel|отч[её]т|реестр|сводка|таблица|'.self::CONDITION_STARTER.'|'.$command.'|'.$postDefinition.')(?![\pL\pN])))';
        $organization = '(?:'.self::ORGANIZATION_QUALIFIER.'организаци(?:и|ей)|(?:'.$recordScope.'|'.$collective.')\s+(?:нашей\s+)?организации)(?![\pL\pN])'.$continuation;
        $scope = '(?:'.$projects.'|'.$organization.')';

        return (preg_match('/(?<![\pL\pN])'.$projects.'/iu', $question) === 1
            || (preg_match('/(?<![\pL\pN])'.$organization.'/iu', $question) === 1
                && (! self::hasAffirmativeCurrentProject($question) || self::comparesCurrentProject($question)
                    || ($applyProjectRestriction && self::hasMultipleProjectScopes($originalQuestion)))))
            && preg_match('/(?<![\pL\pN])(?:не\s+(?:(?:надо|нужно|следует|стоит)\s+)?(?:(?:показыва|покажи|включ|учитыва|учти|добав|бери|брать|ищи|искать|работа)[а-яё]*\s+|(?:выполня|проводи)[а-яё]*\s+поиск\s+)?|(?:кроме|исключая)\s+)(?:(?:в|во|на|по|для|из|с|со)\s+)?'.$scope.'/iu', $question) !== 1
            && (! $applyProjectRestriction || ! self::hasExplicitProjectSections($originalQuestion) || self::hasMultipleProjectScopes($originalQuestion));
    }

    public static function bindSearchScope(string $question, array $arguments, ?int $contextProjectId, bool $projectFilterSupported, ?callable $search = null): array
    {
        $question = AssistantOrganizationRecordVocabulary::normalize($question);
        if (self::withoutExcludedProjects($question) !== $question) {
            throw new AssistantReportScopeAmbiguous;
        }
        if (self::hasOrganizationProjectRestriction($question)) {
            if (! $projectFilterSupported) {
                throw new AssistantReportScopeAmbiguous;
            }
            $arguments['project_id'] = (new self(false, null, $contextProjectId))->restrictedProjectId($question, $search);
        } elseif (self::organizationWide($question)) {
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
        $question = $this->resolveExclusionBoundaries($question, $search);
        $sourceQuestion = $question;
        $question = AssistantOrganizationRecordVocabulary::normalize($question);
        if (! is_array($arguments['sections'] ?? null)) {
            return $arguments;
        }
        $organizationWide = ! $this->requested && self::organizationWide($question);
        if (! $organizationWide) {
            $this->assertResolvedComparisonScope($sourceQuestion, $search);
        }
        $restricted = ! $this->requested && self::hasOrganizationProjectRestriction($question);
        $projectId = $restricted ? $this->restrictedProjectId($question, $search) : $this->projectId;
        if (! $organizationWide && ((! $this->requested && ! $restricted) || $projectId === null)) {
            return $arguments;
        }
        $mixed = $organizationWide && self::hasExplicitProjectSections($question);
        $projectIds = null;
        $resolvedProjectNames = [];
        $projectBindings = [];
        $boundProjects = [];
        $projectSections = [];
        $hasOrganizationSection = false;
        $organizationConstraints = null;
        foreach ($arguments['sections'] as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $scope = $section['scope'] ?? null;
            if (($scope !== null && ! in_array($scope, ['organization', 'project'], true))
                || ($mixed && $scope === null) || (! $organizationWide && $scope === 'organization')) {
                throw new AssistantReportScopeAmbiguous;
            }
            if ($restricted) {
                $id = self::positiveId($section['project_id'] ?? null);
                if ($id !== null && $id !== $projectId && $id !== $this->contextProjectId) {
                    throw new AssistantReportScopeAmbiguous;
                }
                if (is_array($section['filters'] ?? null)) {
                    $section['filters'] = array_values(array_filter($section['filters'],
                        fn (mixed $filter): bool => ! is_array($filter) || ! $this->isSelectedProjectFilter($filter)));
                    $arguments['sections'][$index]['filters'] = $section['filters'];
                }
                $this->assertProjectFilters($section, $projectId);
            }
            $hasOrganizationSection = $hasOrganizationSection || $scope === 'organization';
            if ($organizationWide && $scope === 'project') {
                if (! $mixed) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $projectIds ??= $this->reportProjectIds($question, $search, $resolvedProjectNames, $projectBindings);
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
                $arguments['sections'][$index]['project_id'] = $organizationWide ? null : $projectId;
            }
            if ($organizationWide) {
                if (is_array($section['filters'] ?? null)) {
                    $section['filters'] = array_values(array_filter($section['filters'],
                        fn (mixed $filter): bool => ! is_array($filter) || ! $this->isSelectedProjectFilter($filter)));
                    $arguments['sections'][$index]['filters'] = $section['filters'];
                }
                $organizationConstraints ??= $this->organizationProjectConstraints($question, $search);
                $this->assertOrganizationProjectFilters($section, $organizationConstraints);
            }
        }
        if ($mixed) {
            $projectIds ??= $this->reportProjectIds($question, $search, $resolvedProjectNames, $projectBindings);
            if (! $hasOrganizationSection || array_diff($projectIds, array_keys($boundProjects)) !== []) {
                throw new AssistantReportScopeAmbiguous;
            }
            $comparison = AssistantReportComparisonSpecification::requestsComparison($sourceQuestion, $resolvedProjectNames);
            $requestedSources = $this->requestedSourceScopes($sourceQuestion, $projectIds, $projectBindings, $comparison);
            if (! $comparison) {
                AssistantRequestedSourceScopes::assertReport($requestedSources, $arguments['sections']);
            }
            AssistantReportComparisonSpecification::assertCompatible($question, $arguments['sections'], $projectIds, $resolvedProjectNames, $comparison ? $requestedSources : []);
        }

        return $arguments;
    }

    public function searchCoverageRequirements(string $question, ?callable $search = null): ?array
    {
        if ($this->requested || ! self::organizationWide($question) || ! self::hasExplicitProjectSections($question)) {
            $this->assertResolvedComparisonScope($question, $search);

            return null;
        }
        $resolvedProjectNames = [];
        $projectBindings = [];
        $projectIds = $this->reportProjectIds($question, $search, $resolvedProjectNames, $projectBindings);
        $pairwise = AssistantReportComparisonSpecification::requestsComparison($question, $resolvedProjectNames);

        $subjects = $this->requestedSourceScopes($question, $projectIds, $projectBindings, $pairwise);

        return ['project_ids' => $projectIds, 'pairwise' => $pairwise, ...($subjects === [] ? [] : ['subjects' => $subjects])];
    }

    private function assertResolvedComparisonScope(string $question, ?callable $search): void
    {
        $organization = '/(?<![\pL\pN])организаци[а-яё]*(?![\pL\pN])/iu';
        if (self::hasOrganizationProjectRestriction($question)
            || ! AssistantReportComparisonSpecification::requestsComparison($question)
            || preg_match($organization, AssistantNamedProjectReadScope::withoutQuotedNames($question)) !== 1) {
            return;
        }
        $names = [];
        $bindings = [];
        $this->reportProjectIds($question, $search, $names, $bindings);
        if (! AssistantReportComparisonSpecification::requestsComparison($question, $names)) {
            return;
        }
        $question = self::replaceBoundProjectOperands($question, $bindings);
        if (preg_match($organization, AssistantNamedProjectReadScope::withoutQuotedNames($question)) === 1) {
            throw new AssistantReportScopeAmbiguous;
        }
    }

    private function requestedSourceScopes(string $question, array $projectIds, array $projectBindings, bool $comparison = false): array
    {
        if (str_contains($question, AssistantRequestedSourceScopes::PREFIX)) {
            throw new AssistantReportScopeAmbiguous;
        }
        $question = self::withoutExcludedProjects($question);
        $question = self::replaceBoundProjectOperands($question, $projectBindings);
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $question = preg_replace_callback('/'.self::NUMERIC_PROJECTS.'/iu', static function (array $match): string {
            preg_match_all('/[1-9]\d*/', $match[1], $numbers);

            return implode(' ', array_map(static fn (string $number): string => AssistantRequestedSourceScopes::MARKER.'project_'.$number.'__', $numbers[0]));
        }, $question) ?? $question;
        $question = preg_replace('/(?<![\pL\pN])(?:(?:по\s+всем|во?\s+всех|(?:с|со)\s+всеми)\s+(?:наш(?:им|их|ими)\s+)?(?:проект|объект)[а-яё]*|всех\s+(?:наших\s+)?(?:проект|объект)ов)(?![\pL\pN])/iu', AssistantRequestedSourceScopes::MARKER.'organization__', $question) ?? $question;
        $question = preg_replace_callback('/'.self::CURRENT_PROJECT.'/iu', function (): string {
            if ($this->contextProjectId === null) {
                throw new AssistantReportScopeAmbiguous;
            }

            return AssistantRequestedSourceScopes::MARKER.'project_'.$this->contextProjectId.'__';
        }, $question) ?? $question;
        $question = preg_replace('/(?<![\pL\pN])(?:'.self::ORGANIZATION_QUALIFIER.'|(?:нашей\s+)?)организаци[а-яё]*(?![\pL\pN])/iu', AssistantRequestedSourceScopes::MARKER.'organization__', $question) ?? $question;

        return AssistantRequestedSourceScopes::compile($question, $projectIds, $comparison);
    }

    private static function replaceBoundProjectOperands(string $question, array $projectBindings): string
    {
        usort($projectBindings, static fn (array $first, array $second): int => strlen($second['name']) <=> strlen($first['name']));
        foreach ($projectBindings as $binding) {
            $name = preg_quote($binding['name'], '/');
            $operand = $binding['quoted'] ? '[«“„"]\s*'.$name.'\s*[»”“"]' : $name;
            $question = preg_replace('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])\s*:?\s*(?:'.$operand.')(?![\pL\pN])/iu',
                ' '.AssistantRequestedSourceScopes::MARKER.'project_'.$binding['project_id'].'__ ', $question) ?? $question;
        }

        return $question;
    }

    private static function recordNouns(): string
    {
        return '(?:проект(?:ы|ам(?:и)?|ов)|объект(?:ы|ам(?:и)?|ов)|акт(?:ы|ам(?:и)?|ов)|договор(?:ы|ам(?:и)?|ов)|журнал(?:ы|ам(?:и)?|ов)|сотрудник(?:и|ам(?:и)?|ов)|пользовател(?:и|ям(?:и)?|ей)|плат[её]ж(?:и|ам(?:и)?|ей)|смет(?:ы|ам(?:и)?)?|задач(?:и|ам(?:и)?)?|работ(?:ы|ам(?:и)?)?|документ(?:ы|ам(?:и)?|ов)|тендер(?:ы|ам(?:и)?|ов)|заяв(?:ки|кам(?:и)?|ок)|закуп(?:ки|кам(?:и)?|ок)|материал(?:ы|ам(?:и)?|ов)|поставщик(?:и|ам(?:и)?|ов)|контрагент(?:ы|ам(?:и)?|ов)|склад(?:ы|ам(?:и)?|ов)|бригад(?:ы|ам(?:и)?)?'
            .'|(?:позици(?:и|ям(?:и)?|й)|ресурс(?:ы|ам(?:и)?|ов)|раздел(?:ы|ам(?:и)?|ов))(?:\s+смет(?:ы|ам(?:и)?|ах)?)?'
            .'|дефект(?:ы|ам(?:и)?|ов)|замечани(?:я|ям(?:и)?|й)|несоответстви(?:я|ям(?:и)?|й)|инцидент(?:ы|ам(?:и)?|ов)|происшестви(?:я|ям(?:и)?|й)|нарушени(?:я|ям(?:и)?|й)|инструктаж(?:и|ам(?:и)?|ей)|проверк(?:и|ам(?:и)?)?|инспекци(?:и|ям(?:и)?|й)'
            .'|наряд(?:ы|ам(?:и)?|ов)(?:-допуск(?:и|ам(?:и)?|ов))?|табел(?:и|ям(?:и)?|ей)|трудозатрат(?:ы|ам(?:и)?)?|начислени(?:я|ям(?:и)?|й)|выработк(?:и|ам(?:и)?)?|запис(?:и|ям(?:и)?|ей)(?:\s+(?:времени|табеля|выработки))?'
            .'|сдел(?:ки|кам(?:и)?|ок)|лид(?:ы|ам(?:и)?|ов)|компани(?:и|ям(?:и)?|й)|контакт(?:ы|ам(?:и)?|ов)|обращени(?:я|ям(?:и)?|й)|активност(?:и|ям(?:и)?|ей)|(?:коммерческ[а-яё]*\s+)?предложени(?:я|ям(?:и)?|й)|стать(?:и|ям(?:и)?|ей)(?:\s+базы\s+знаний)?'
            .'|остат(?:ки|кам(?:и)?|ков)|баланс(?:ы|ам(?:и)?|ов)|движени(?:я|ям(?:и)?|й)(?:\s+материалов)?|распределени(?:я|ям(?:и)?|й)|резерв(?:ы|ам(?:и)?|ов)|ячей(?:ки|кам(?:и)?|ек)(?:\s+хранения)?|актив(?:ы|ам(?:и)?|ов)|достав(?:ки|кам(?:и)?|ок)|постав(?:ки|кам(?:и)?|ок)|заказ(?:ы|ам(?:и)?|ов)|при[её]м(?:ки|кам(?:и)?|ок)|согласовани(?:я|ям(?:и)?|й)'
            .'|график(?:и|ам(?:и)?|ов)|расписани(?:я|ям(?:и)?|й)|комплект(?:ы|ам(?:и)?|ов)(?:\s+документации)?|пакет(?:ы|ам(?:и)?|ов)(?:\s+документации)?|артефакт(?:ы|ам(?:и)?|ов)|верси(?:и|ям(?:и)?|й)|набор(?:ы|ам(?:и)?|ов)(?:\s+моделей)?'
            .'|машин(?:ы|ам(?:и)?)?|механизм(?:ы|ам(?:и)?|ов)|назначени(?:я|ям(?:и)?|й)|просто(?:и|ям(?:и)?|ев)|сменн[а-яё]*\s+отч[её]т(?:ы|ам(?:и)?|ов)|ремонт(?:ы|ам(?:и)?|ов)|выдач(?:и|ам(?:и)?)?(?:\s+топлива)?'
            .'|запрос(?:ы|ам(?:и)?|ов)(?:\s+(?:изменений|информации))?|изменени(?:я|ям(?:и)?|й)|претензи(?:и|ям(?:и)?|й)|чек-лист(?:ы|ам(?:и)?|ов)|пункт(?:ы|ам(?:и)?|ов)(?:\s+чек-листов)?|сесси(?:и|ям(?:и)?|й)(?:\s+при[её]мки)?|корректирующ[а-яё]*\s+мероприяти(?:я|ям(?:и)?|й))';
    }

    private static function hasAffirmativeCurrentProject(string $question): bool
    {
        $question = self::withoutNegativeCurrentProject($question);
        $question = preg_replace('/(?<![\pL\pN])(?:во?\s+всех|по\s+всем|(?:(?:с|со)\s+)?всеми|всех|всем|все)\s+наш(?:ими|их|им|и)\s+(?:проект|объект)[а-яё]*(?![\pL\pN])/iu', ' ', $question) ?? $question;

        return preg_match('/'.self::CURRENT_PROJECT.'/iu', $question) === 1;
    }

    private static function withoutNegativeCurrentProject(string $question): string
    {
        $question = AssistantNamedProjectReadScope::withoutQuotedNames(self::withoutExcludedProjects($question));
        $negative = '(?<![\pL\pN])(?:не\s+включая\s+|не\s+(?:(?:огранич[а-яё]*|только)\s+(?:[\pL-]+\s+){0,3})?|без\s+(?:ограничени[а-яё]*\s+(?:[\pL-]+\s+){0,3})?|(?:кроме|исключая|за\s+исключением)\s+)';

        return preg_replace('/'.$negative.'(?:(?:в|во|на|по|для|из|с|со)\s+)?'.self::CURRENT_PROJECT.'/iu', ' ', $question) ?? $question;
    }

    private static function comparesCurrentProject(string $question): bool
    {
        $question = self::withoutNegativeCurrentProject($question);
        if (preg_match('/'.AssistantReportComparisonSpecification::COMPARISON_WORD.'/iu', $question) !== 1) {
            return false;
        }
        preg_match_all('/(?<![\pL\pN])(?:с|со|и|против|относительно)\s+/iu', $question, $relations, PREG_OFFSET_CAPTURE);
        foreach ($relations[0] as [$relation, $offset]) {
            if (self::startsWithProjectOperand(substr($question, $offset + strlen($relation)), self::CURRENT_PROJECT)) {
                return true;
            }
        }

        return self::hasProjectFirstComparison($question, self::CURRENT_PROJECT)
            || preg_match('/'.self::CURRENT_PROJECT.'\s+(?:и|с|со|против|относительно)\s+(?:(?:в|во|на|по)\s+)?(?:всех|всеми|всем|все|всей)(?![\pL\pN])/iu', $question) === 1;
    }

    private static function hasProjectFirstComparison(string $question, string $projectPattern): bool
    {
        preg_match_all('/(?:'.self::QUOTED_NAME.')(*SKIP)(*F)|(?:'.$projectPattern.')\s+'.AssistantReportComparisonSpecification::COMPARISON_WORD.'\s+(?:с|со|против|относительно)\s+(?<organization>.+)/iu', $question, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (self::organizationScope($match['organization'], false)) {
                return true;
            }
        }

        return false;
    }

    private static function startsWithProjectOperand(string $text, string $projectPattern): bool
    {
        $text = preg_replace('/^(?:'.AssistantRequestedSourceScopes::SECTION_MODIFIER.'\s+)+/iu', '', $text) ?? $text;
        $preposition = '(?:(?:по|для|в|во|на|из)\s+)?';
        if (preg_match('/^'.$preposition.'(?:'.$projectPattern.')/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/^([\pL\pN_-]+(?:\s+[\pL\pN_-]+){0,15}?)\s+'.$preposition.'(?:'.$projectPattern.')/iu', $text, $match) !== 1
            || preg_match('/(?<![\pL\pN])(?:не|без|кроме|исключая)(?![\pL\pN])/iu', $match[1]) === 1) {
            return false;
        }

        $phrase = $match[1];
        for ($depth = 0; $depth < 3; $depth++) {
            if (preg_match('/^(?:'.self::recordNouns().')$/iu', $phrase) === 1
                || AssistantOrganizationRecordVocabulary::matchesEntityPhrase($phrase)) {
                return true;
            }
            if (preg_match('/^'.AssistantRequestedSourceScopes::SOURCE_WRAPPER.'\s+(?:по|о|об|про)\s+(.+)$/iu', $phrase, $wrapper) !== 1) {
                return false;
            }
            $phrase = $wrapper[1];
        }

        return false;
    }

    private static function hasExplicitProjectSections(string $question): bool
    {
        $question = self::withoutExcludedProjects($question);
        $quotedProject = preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*:?\s*[«„“"]/iu', $question) === 1;
        $question = self::withoutNegativeCurrentProject($question);

        return $quotedProject || self::comparesCurrentProject($question)
            || (self::hasAffirmativeCurrentProject($question) && self::hasMultipleProjectScopes($question))
            || preg_match('/(?<![\pL\pN])(?:проект|объект)[а-яё]*\s*(?:№|#|id)?\s*[1-9]\d*(?![\pL\pN])/iu', $question) === 1
            || AssistantReportEntityQuery::extract($question, 'project') !== null
            || AssistantReportEntityQuery::hasAmbiguousName($question, 'project');
    }

    private static function hasMultipleProjectScopes(string $question): bool
    {
        $question = self::withoutExcludedProjects($question);
        $explicit = '(?:'.self::CURRENT_PROJECT.'|'.self::NUMERIC_PROJECTS.'|(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])\s*:?\s*(?:'.self::QUOTED_NAME.'))';
        if (self::hasProjectFirstComparison($question, $explicit)) {
            return true;
        }
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $comparisonPrefix = $question;
        if (preg_match('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])/iu', $question, $mention, PREG_OFFSET_CAPTURE) === 1) {
            $comparisonPrefix = substr($question, 0, $mention[0][1]);
        }
        $project = '(?:'.self::CURRENT_PROJECT.'|(?:проект|объект)[а-яё]*(?![\pL\pN]))';
        if (preg_match('/'.AssistantReportComparisonSpecification::COMPARISON_WORD.'/iu', $comparisonPrefix) === 1) {
            preg_match_all('/(?<![\pL\pN])(?:с|со|против|относительно)\s+/iu', $question, $relations, PREG_OFFSET_CAPTURE);
            foreach ($relations[0] as [$relation, $offset]) {
                $prefix = substr($question, 0, $offset);
                $tail = substr($question, $offset + strlen($relation));
                if ((self::organizationScope($prefix, false) && self::startsWithProjectOperand($tail, $project))
                    || (self::organizationScope($tail, false) && preg_match('/(?<![\pL\pN])'.$project.'/iu', $prefix) === 1)) {
                    return true;
                }
            }
            preg_match_all('/'.self::NUMERIC_PROJECTS.'/iu', $question, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($lists as $list) {
                $prefix = substr($question, 0, $list[0][1]);
                if (preg_match_all('/[1-9]\d*/', $list[1][0]) > 1
                    && preg_match('/(?:[.!?]|(?<![\pL\pN])и)\s*'.AssistantReportComparisonSpecification::COMPARISON_WORD.'\s*$/iu', $prefix) === 1
                    && self::organizationScope($prefix, false)) {
                    return true;
                }
            }
        }
        preg_match_all('/(?<![\pL\pN])'.AssistantRequestedSourceScopes::ADDITIVE_CONNECTOR.'\s+/iu', $question, $conjunctions, PREG_OFFSET_CAPTURE);
        foreach ($conjunctions[0] as [$conjunction, $offset]) {
            $prefix = substr($question, 0, $offset);
            $tail = substr($question, $offset + strlen($conjunction));
            if ((self::organizationScope($tail, false) && preg_match('/(?<![\pL\pN])'.$project.'/iu', $prefix) === 1)
                || (self::organizationScope($prefix, false) && self::startsWithProjectOperand($tail, $project))) {
                return true;
            }
        }

        return false;
    }

    private static function hasOrganizationProjectRestriction(string $question): bool
    {
        return self::organizationScope($question, false) && ! self::organizationWide($question);
    }

    private function restrictedProjectId(string $question, ?callable $search): int
    {
        $ids = $this->reportProjectIds($question, $search);
        if (count($ids) !== 1) {
            throw new AssistantReportScopeAmbiguous;
        }
        $id = $ids[0];
        if (preg_match('/'.self::NUMERIC_PROJECTS.'/iu', AssistantNamedProjectReadScope::withoutQuotedNames(self::withoutExcludedProjects($question))) === 1) {
            $result = $search === null ? null : $search(['query' => (string) $id, 'project_id' => $id, 'limit' => 2]);
            if (! is_array($result) || ($result['status'] ?? null) !== 'success' || ($result['result_window']['has_more'] ?? null) !== false
                || ! is_array($result['results'] ?? null) || count($result['results']) !== 1 || ($result['results'][0]['id'] ?? null) !== $id) {
                throw new AssistantReportScopeAmbiguous;
            }
        }

        return $id;
    }

    private function reportProjectIds(string $question, ?callable $search, array &$resolvedProjectNames = [], array &$projectBindings = []): array
    {
        $resolvedProjectNames = [];
        $projectBindings = [];
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
        $noun = '(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])';
        $parts = preg_split('/(?:'.self::QUOTED_NAME.')(*SKIP)(*F)|(?:\s+(?:и|или)\s+|,\s*(?:и\s+)?)(?=(?:(?:по|для|в|во|на|из)\s+)?'.$noun.'\s)/iu', $nameQuestion, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [[$nameQuestion, 0]];
        $parts = array_values(array_filter($parts, static fn (array $part): bool => preg_match('/'.$noun.'/iu', $part[0]) === 1));
        if (count($parts) > 1) {
            if ($search === null) {
                throw new AssistantReportScopeAmbiguous;
            }
            foreach (array_reverse(range(1, count($parts))) as $boundary) {
                $end = $parts[$boundary - 1][1] + strlen($parts[$boundary - 1][0]);
                $prefix = substr($nameQuestion, 0, $end);
                if (preg_match('/'.$noun.'(?:\s*:\s*|\s+)(?<name>.+)$/isu', $prefix, $full) !== 1) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $quoted = preg_match('/^(?:«|“|„|")/u', $full['name']) === 1;
                if ($quoted && $boundary > 1) {
                    continue;
                }
                $name = $quoted ? AssistantReportEntityQuery::extract($prefix, 'project', preservePrepositions: true)
                    : preg_replace('/^[\s\x{0022}\x{0027}«»“”.:]+|[\s\x{0022}\x{0027}«»“”.:]+$/u', '', $full['name']);
                if (! is_string($name) || $name === '') {
                    throw new AssistantReportScopeAmbiguous;
                }
                foreach (self::reportNameCandidates($name, $quoted) as $candidate) {
                    $id = self::exactReportProjectId($candidate, $search);
                    if ($id !== null) {
                        $resolvedProjectNames[] = $candidate;
                        $projectBindings[] = ['name' => $candidate, 'project_id' => $id, 'quoted' => $quoted];
                        if ($boundary < count($parts)) {
                            $names = [];
                            $bindings = [];
                            $ids = [...$ids, ...$this->reportProjectIds(substr($nameQuestion, $parts[$boundary][1]), $search, $names, $bindings)];
                            $resolvedProjectNames = [...$resolvedProjectNames, ...$names];
                            $projectBindings = [...$projectBindings, ...$bindings];
                        }

                        return array_values(array_unique([...$ids, $id]));
                    }
                }
            }
            throw new AssistantReportScopeAmbiguous;
        }
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
            $id = self::exactReportProjectId($candidate, $search);
            if ($id === null) {
                continue;
            }
            $resolvedProjectNames[] = $candidate;
            $projectBindings[] = ['name' => $candidate, 'project_id' => $id, 'quoted' => $quoted];

            return array_values(array_unique([...$ids, $id]));
        }

        throw new AssistantReportScopeAmbiguous;
    }

    private static function exactReportProjectId(string $candidate, callable $search): ?int
    {
        $result = $search(['query' => $candidate, 'name_match' => 'exact', 'limit' => 2]);
        $rows = is_array($result) ? ($result['results'] ?? null) : null;
        if (! is_array($result) || ($result['status'] ?? null) !== 'success' || ! is_array($rows)
            || ($result['result_window']['has_more'] ?? null) !== false) {
            throw new AssistantReportScopeAmbiguous;
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || ! is_int($rows[0]['id'] ?? null) || $rows[0]['id'] < 1) {
            throw new AssistantReportScopeAmbiguous;
        }

        return $rows[0]['id'];
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
        return preg_replace_callback(self::excludedProjectPattern(), static function (array $match): string {
            $operand = $match['excluded_project'] ?? '';
            $boundaries = self::exclusionBoundaries($operand);

            return $boundaries === [] ? ' ' : ' '.substr($operand, $boundaries[0]['offset']).' ';
        }, $question) ?? $question;
    }

    private static function exclusionBoundaries(string $operand): array
    {
        $noun = '(?<![\pL\pN])(?:проект|объект)[а-яё]*(?![\pL\pN])';
        if (preg_match('/^(?:'.self::CURRENT_PROJECT.'|'.self::NUMERIC_PROJECTS.'|'.$noun.'\s*:?\s*[«“„"])/iu', $operand) === 1
            || preg_match('/^'.$noun.'\s*:?\s*(.+)$/isu', $operand, $name, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }
        $project = '(?:'.self::CURRENT_PROJECT.'|'.$noun.')';
        preg_match_all('/(?<![\pL\pN])'.AssistantRequestedSourceScopes::ADDITIVE_CONNECTOR.'\s+/iu', $name[1][0], $connectors, PREG_OFFSET_CAPTURE);
        $boundaries = [];
        foreach ($connectors[0] as [$connector, $offset]) {
            $prefix = trim(substr($name[1][0], 0, $offset));
            $tail = substr($name[1][0], $offset + strlen($connector));
            if ($prefix !== '' && preg_match('/^(?:(?:по|для|в|во|на|из)\s+)?'.$project.'/iu', $tail) !== 1
                && self::startsWithProjectOperand($tail, $project)) {
                $boundaries[] = ['name' => $prefix, 'offset' => $name[1][1] + $offset];
            }
        }

        return $boundaries;
    }

    private function resolveExclusionBoundaries(string $question, ?callable $search): string
    {
        for ($depth = 0; $depth < 5; $depth++) {
            preg_match_all(self::excludedProjectPattern(), $question, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $changed = false;
            foreach (array_reverse($matches) as $match) {
                $operand = $match['excluded_project'][0] ?? '';
                $boundaries = self::exclusionBoundaries($operand);
                if ($boundaries === []) {
                    continue;
                }
                if ($search === null || count($boundaries) > 5) {
                    throw new AssistantReportScopeAmbiguous;
                }
                preg_match('/^(?:проект|объект)[а-яё]*\s*:?\s*(.+)$/isu', $operand, $name);
                $id = self::exactReportProjectId(trim($name[1]), $search);
                $replacement = $id === null ? null : 'проект '.$id;
                foreach (array_reverse($boundaries) as $boundary) {
                    if ($replacement !== null) {
                        break;
                    }
                    $id = self::exactReportProjectId($boundary['name'], $search);
                    if ($id !== null) {
                        $replacement = 'проект '.$id.' '.substr($operand, $boundary['offset']);
                    }
                }
                if ($replacement === null) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $question = substr_replace($question, $replacement, $match['excluded_project'][1], strlen($operand));
                $changed = true;
            }
            if (! $changed) {
                return $question;
            }
        }

        throw new AssistantReportScopeAmbiguous;
    }

    private static function excludedProjectPattern(): string
    {
        $noun = '(?<![\pL\pN])(?:проект|объект)[а-яё]*(?![\pL\pN])';
        $stop = '(?:\s+(?:с|со|против|относительно)\s+(?:'.self::CURRENT_PROJECT.'|'.$noun.')|[,;.!?\r\n]|$)';
        $name = '(?:(?!'.$stop.')[^,;.!?\r\n])+';
        $operand = '(?:'.self::CURRENT_PROJECT.'|'.self::NUMERIC_PROJECTS.'|'.$noun.'\s*:?\s*(?:'.self::QUOTED_NAME.')|'.$noun.'\s+'.$name.')';
        $excluded = '(?:'.$operand.'|'.$noun.')';
        $directive = '(?:не\s+(?:(?:надо|нужно|следует|стоит)\s+)?(?:включ(?:ать|ай(?:те)?|и(?:те)?)|учитыва(?:ть|й(?:те)?|ем)|уч(?:ти(?:те)?|есть))|исключ(?:ить|и(?:те)?|ай(?:те)?))(?![\pL\pN])';
        $negative = '(?<![\pL\pN])(?:без|кроме|исключая|не\s+включая|за\s+исключением|не(?:\s+(?:в|во|на|по|для|из|с|со))?|'.$directive.')\s+';
        $ending = '(?=\s*(?:$|[,;.!?\r\n]|(?:в|из|для)\s+(?:отч[её]т[а-яё]*|сводк[а-яё]*|выборк[а-яё]*|CSV|XLSX|Excel|PDF)(?![\pL\pN])))';
        $postfix = '\s*(?:[,—–:-]\s*)?(?:пожалуйста\s*,?\s*)?'.$directive.$ending;

        return '/(?:'.self::QUOTED_NAME.')(*SKIP)(*F)|'.$negative.'(?<excluded_project>'.$excluded.')|(?<postfix_excluded_project>'.$operand.')'.$postfix.'/iu';
    }

    private function organizationProjectConstraints(string $question, ?callable $search): array
    {
        $constraints = [];
        $noun = '(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])';
        $quoted = '(?:'.self::QUOTED_NAME.')(*SKIP)(*F)|';
        $question = preg_replace('/'.$quoted.'без\s+'.$noun.'\s+(?:тоже|также)\s+(?:учти|включи|добавь)(?:те)?(?![\pL\pN])/iu', 'включая непривязанные записи', $question) ?? $question;
        $nullRequest = '(?:только|лишь|исключительно)\s+(?<null_kind>без|с)\s+(?:привязк(?:и|ой)\s+к\s+|(?:указанным|заданным)\s+)?'.$noun.'(?=\s*(?:$|[,.;!?]))';
        $question = preg_replace_callback('/'.$quoted.$nullRequest.'/iu', static function (array $match) use (&$constraints): string {
            $operator = mb_strtolower($match['null_kind']) === 'без' ? 'is_null' : 'is_not_null';
            $constraints[$operator] = true;

            return 'с требуемой привязкой';
        }, $question) ?? $question;
        preg_match_all(self::excludedProjectPattern(), $question, $excluded, PREG_SET_ORDER);
        foreach ($excluded as $match) {
            $project = trim(($match['excluded_project'] ?? '') !== '' ? $match['excluded_project'] : ($match['postfix_excluded_project'] ?? ''));
            if (preg_match('/^'.$noun.'$/iu', $project) === 1) {
                if (preg_match('/^без\s+/iu', $match[0]) !== 1) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $constraints['is_null'] = true;

                continue;
            }
            foreach ($this->reportProjectIds($project, $search) as $id) {
                $constraints['neq:'.$id] = true;
            }
        }
        if (isset($constraints['is_null']) && count($constraints) > 1) {
            throw new AssistantReportScopeAmbiguous;
        }

        return $constraints;
    }

    private function assertOrganizationProjectFilters(array $section, array $constraints): void
    {
        $seen = [];
        foreach (is_array($section['filters'] ?? null) ? $section['filters'] : [] as $filter) {
            if (! is_array($filter) || ($filter['field'] ?? null) !== 'project_id') {
                continue;
            }
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;
            $key = match ($operator) {
                'neq' => self::positiveId($value) === null ? null : 'neq:'.self::positiveId($value),
                'is_null', 'is_not_null' => $value === null ? $operator : null,
                default => null,
            };
            if ($key === null || ! isset($constraints[$key])) {
                throw new AssistantReportScopeAmbiguous;
            }
            $seen[$key] = true;
        }
        if (array_diff_key($constraints, $seen) !== []) {
            throw new AssistantReportScopeAmbiguous;
        }
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
                'is_not_null' => false,
                default => true,
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
