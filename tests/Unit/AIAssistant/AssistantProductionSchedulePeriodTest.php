<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProductionSchedulePeriod;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectClarificationIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;
use PHPUnit\Framework\TestCase;

final class AssistantProductionSchedulePeriodTest extends TestCase
{
    public function test_calendar_start_periods_resolve_without_inheriting_project_start(): void
    {
        foreach (['2026-10-21' => ['года' => '2026-01-01', 'месяца' => '2026-10-01', 'недели' => '2026-10-19'],
            '2026-01-01' => ['года' => '2026-01-01', 'месяца' => '2026-01-01', 'недели' => '2025-12-29']] as $today => $periods) {
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($today.' 10:00:00', 'Europe/Moscow'));
            try {
                foreach ($periods as $unit => $start) {
                    foreach (['с начала ', 'с начала текущего ', 'с начала этого '] as $prefix) {
                        $phrase = $prefix.$unit;
                        $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $today];
                        self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                            ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                        $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                            'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]],
                            static fn (array $arguments): array => ['status' => 'success',
                                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                        self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                    }
                }
                foreach (['с начала года и вчера', 'с начала месяца и с начала недели', 'с начала недели и за 2025 год'] as $phrase) {
                    self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                        AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                }
            } finally {
                \Carbon\CarbonImmutable::setTestNow();
            }
        }
    }

    public function test_previous_month_resolves_calendar_boundaries_in_direct_and_named_questions(): void
    {
        foreach (['2026-01-01' => ['2025-12-01', '2025-12-31'], '2024-03-01' => ['2024-02-01', '2024-02-29']] as $today => [$start, $end]) {
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($today.' 10:00:00', 'Europe/Moscow'));
            try {
                $phrase = 'за предыдущий месяц';
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]));
                $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase]],
                    static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            } finally {
                \Carbon\CarbonImmutable::setTestNow();
            }
        }
    }

    public function test_previous_year_resolves_calendar_boundaries_in_direct_and_named_questions(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-01 10:00:00', 'Europe/Moscow'));
        try {
            $phrase = 'за предыдущий год';
            $expected = ['project_id' => 102, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]));
            $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase]],
                static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase.' и за предыдущий месяц']]));
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_hyphenated_date_suffix_keeps_the_literal_name_probe_and_resolves_the_named_project(): void
    {
        foreach ([['01-10-2026', '15-10-2026'], ['01.10.2026', '15.10.2026'],
            ['01/10/2026', '15/10/2026'], ['2026-10-01', '2026-10-15']] as [$start, $end]) {
            $name = 'Северный дом с '.$start.' по '.$end;
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта '.$name,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $seen = [];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use (&$seen): array {
                $seen[] = $arguments;

                return ['status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [],
                    'result_window' => ['has_more' => false]];
            });
            self::assertSame([$name, 'Северный дом'], array_column($seen, 'query'));
            self::assertSame(['exact', 'exact'], array_column($seen, 'name_match'));
            self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15'],
                AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        }
    }

    public function test_past_singular_durations_resolve_as_one_unit_in_direct_and_named_questions(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за прошедший день' => '2026-10-18', 'за минувший день' => '2026-10-18',
                'за прошедшую неделю' => '2026-10-12', 'за минувшую неделю' => '2026-10-12',
                'за прошедший месяц' => '2026-09-19', 'за минувший месяц' => '2026-09-19',
                'за прошедший год' => '2025-10-19', 'за минувший год' => '2025-10-19',
                'за прошедшие сутки' => '2026-10-18', 'за минувшие сутки' => '2026-10-18'] as $phrase => $start) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase));
                $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase]],
                    static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком за прошедший месяц и вчера']]));
            foreach (['за прошедшие дни', 'за минувшие недели', 'за минувшие годы'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_calendar_week_phrases_keep_named_project_and_boundaries_across_years(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-01 00:30:00', 'Europe/Moscow'));
        try {
            foreach (['на этой неделе' => ['2025-12-29', '2026-01-01'],
                'за эту неделю' => ['2025-12-29', '2026-01-01'],
                'на текущей неделе' => ['2025-12-29', '2026-01-01'],
                'на прошлой неделе' => ['2025-12-22', '2025-12-28'],
                'за прошлую неделю' => ['2025-12-22', '2025-12-28'],
                'в предыдущую неделю' => ['2025-12-22', '2025-12-28'],
                'на позапрошлой неделе' => ['2025-12-15', '2025-12-21']] as $phrase => [$start, $end]) {
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => [
                    'status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [],
                    'result_window' => ['has_more' => false],
                ]);
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                    AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            foreach (['на этой неделе и вчера', 'на прошлой неделе и за октябрь 2026',
                'на прошлой неделе и 3 недели назад', 'на прошлой неделе и на этой неделе'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
            }
            foreach (['2026-01-04' => ['2025-12-29', '2026-01-04'], '2026-01-05' => ['2026-01-05', '2026-01-05']] as $day => [$start, $end]) {
                \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($day.' 12:00:00', 'Europe/Moscow'));
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком на этой неделе']]));
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_weeks_ago_are_resolved_and_removed_from_named_scope_without_hiding_other_periods(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['1 неделю назад' => ['2026-10-06', '2026-10-13'],
                '2 недели назад' => ['2026-09-29', '2026-10-06'],
                '3 недели назад' => ['2026-09-22', '2026-09-29'],
                'за 3 недели назад' => ['2026-09-22', '2026-09-29'],
                'ЗА 3 НЕДЕЛИ НАЗАД' => ['2026-09-22', '2026-09-29']] as $phrase => [$start, $end]) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase));
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => [
                    'status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [],
                    'result_window' => ['has_more' => false],
                ]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            foreach (['0 недель назад', '100 недель назад', 'три недели назад', 'за три недели назад',
                '3 недели назад и сегодня', '3 недели назад и за 2026 год', '3 недели назад и 2 недели назад'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_singular_last_durations_resolve_as_one_unit_and_plural_unknown_quantities_stay_unresolved(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за последний день' => '2026-10-18', 'за последние сутки' => '2026-10-18',
                'за последнюю неделю' => '2026-10-12', 'за крайний день' => '2026-10-18',
                'за крайнюю неделю' => '2026-10-12', 'за последний месяц' => '2026-09-19',
                'за последний год' => '2025-10-19'] as $phrase => $start) {
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
            foreach (['за последние дни', 'за последние недели', 'за последние годы'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_resolver_supported_durations_never_become_all_time_or_part_of_the_project_name(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за день' => '2026-10-18', 'за сутки' => '2026-10-18', 'за неделю' => '2026-10-12',
                'за месяц' => '2026-09-19', 'за год' => '2025-10-19', 'за полгода' => '2026-04-19',
                'полгода' => '2026-04-19', 'за последние полгода' => '2026-04-19',
                'за два месяца' => '2026-08-19', 'за последние две недели' => '2026-10-05'] as $phrase => $start) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, [
                    'request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase],
                ]), $phrase);
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => [
                    'status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false],
                ]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            foreach (['за неделю и за месяц', 'за последние две недели и за 2026 год', 'полгода и вчера'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_relative_days_and_project_start_keep_named_scope_and_resolve_without_clarification(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-01 00:30:00', 'Europe/Moscow'));
        try {
            foreach (['сегодня' => ['2026-01-01', '2026-01-01'], 'за сегодня' => ['2026-01-01', '2026-01-01'],
                'вчера' => ['2025-12-31', '2025-12-31'], 'за вчера' => ['2025-12-31', '2025-12-31'],
                'позавчера' => ['2025-12-30', '2025-12-30'], 'с начала проекта' => [null, '2026-01-01']] as $phrase => [$start, $end]) {
                $query = 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase;
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
                $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => [
                    'status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false],
                ]);
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end], AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            foreach (['сегодня и вчера', 'сегодня за октябрь 2026', 'с начала проекта за октябрь 2026', 'с начала проекта и вчера'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_open_lower_bound_requires_a_valid_upper_bound_and_remains_a_known_period(): void
    {
        self::assertTrue(AssistantProductionSchedulePeriod::validArguments(['project_id' => 102, 'period_start' => null, 'period_end' => '2026-10-31']));
        foreach ([['project_id' => 102, 'period_start' => null, 'period_end' => '2026-02-30'],
            ['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => null]] as $arguments) {
            self::assertFalse(AssistantProductionSchedulePeriod::validArguments($arguments));
        }
    }

    public function test_period_prepositions_preserve_the_named_project_and_resolve_known_dates(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['на текущий год' => ['2026-01-01', '2026-10-19'], 'в этом году' => ['2026-01-01', '2026-10-19'],
                'на текущий месяц' => ['2026-10-01', '2026-10-19'], 'в прошлом месяце' => ['2026-09-01', '2026-09-30'],
                'на октябрь 2026' => ['2026-10-01', '2026-10-31'], 'на III квартал 2026' => ['2026-07-01', '2026-09-30']] as $phrase => [$start, $end]) {
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => [
                    'status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false],
                ]);
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end], AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_period_resume_clears_project_references_in_both_supported_shapes(): void
    {
        foreach (['entity_refs', 'entity_references'] as $key) {
            $context = [$key => [['entity_type' => 'project', 'entity_id' => 104], ['type' => 'project', 'id' => 105],
                ['type' => 'estimate', 'id' => 9, 'entity_type' => 'project', 'entity_id' => 106], ['entity_type' => 'estimate', 'entity_id' => 3]]];
            $resumed = AssistantProductionSchedulePeriod::resumePayload(['context' => $context], ['project_id' => 102]);
            self::assertSame(102, AssistantRequestProjectScope::fromPlan(['request' => $resumed])->projectId);
            self::assertSame([['entity_type' => 'estimate', 'entity_id' => 3], ['type' => 'project', 'id' => 102]], $resumed['context'][$key]);
            self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'], AssistantRequiredProjectRead::fromPlan([
                'request' => ['message' => 'Сверь выполненные работы с графиком за октябрь 2026'] + $resumed,
            ])['arguments']);
        }
    }

    public function test_period_adjectives_do_not_create_a_filter_or_hide_a_real_period(): void
    {
        foreach (['с квартальным графиком', 'с текущим квартальным графиком', 'с последним недельным графиком',
            'с текущим годовым графиком', 'с текущим месячным графиком', 'с графиком жилого квартала'] as $phrase) {
            self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы '.$phrase]]), $phrase);
            self::assertSame('Дом '.$phrase, AssistantProductionSchedulePeriod::withoutSuffix('Дом '.$phrase));
        }
        self::assertSame(['project_id' => 102, 'period_start' => '2026-07-01', 'period_end' => '2026-09-30'],
            AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с квартальным графиком за 3 квартал 2026']]));
        self::assertSame(['project_id' => 102, 'period_start' => '2026-07-01', 'period_end' => '2026-09-30'],
            AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком жилого квартала за 3 квартал 2026']]));
        self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'],
            AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком жилого квартала за октябрь 2026']]));
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за последние 2 месяца' => '2026-08-19', 'за последние 2 недели' => '2026-10-05', 'за текущий год' => '2026-01-01'] as $phrase => $start) {
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                self::assertSame('Дом', AssistantProductionSchedulePeriod::withoutSuffix('Дом '.$phrase));
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_a_quarter_does_not_hide_another_requested_period(): void
    {
        foreach (['за 3 квартал 2026 и за прошлый год', 'в III квартале 2026 и сегодня',
            'за последний квартал и за текущий месяц', 'за текущий квартал и за последнюю неделю'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
        }
    }

    public function test_relative_quarters_keep_rolling_and_calendar_meanings(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за последний квартал' => ['2026-07-19', '2026-10-19'], 'за квартал' => ['2026-07-19', '2026-10-19'],
                'за текущий квартал' => ['2026-10-01', '2026-10-19'], 'в этом квартале' => ['2026-10-01', '2026-10-19'],
                'за прошлый квартал' => ['2026-07-01', '2026-09-30'], 'в предыдущем квартале' => ['2026-07-01', '2026-09-30']] as $phrase => [$start, $end]) {
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
            }
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-12 10:00:00', 'Europe/Moscow'));
            self::assertSame(['project_id' => 102, 'period_start' => '2025-10-01', 'period_end' => '2025-12-31'],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком за прошлый квартал']]));
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_explicit_calendar_year_is_resolved_without_reasking_the_year(): void
    {
        foreach (['за 2026 год', 'в 2026 году', 'на 2026 год', 'за 2026', 'за 2024 год'] as $phrase) {
            $year = str_contains($phrase, '2024') ? '2024' : '2026';
            self::assertSame(['project_id' => 102, 'period_start' => $year.'-01-01', 'period_end' => $year.'-12-31'],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
            self::assertSame('Дом', AssistantProductionSchedulePeriod::withoutSuffix('Дом '.$phrase));
        }
        foreach (['за 2025 и 2026 годы', 'за 2025 год и за 2026 год', 'за 2026 год и за октябрь'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$phrase]]), $phrase);
        }
    }

    public function test_numbered_quarters_use_calendar_boundaries_and_unknown_quarters_require_dates(): void
    {
        foreach ([1 => ['I', 'первый', 'первом', '2026-01-01', '2026-03-31'], 2 => ['II', 'второй', 'втором', '2026-04-01', '2026-06-30'],
            3 => ['III', 'третий', 'третьем', '2026-07-01', '2026-09-30'], 4 => ['IV', 'четвёртый', 'четвёртом', '2026-10-01', '2026-12-31']] as $number => [$roman, $ordinal, $locative, $start, $end]) {
            foreach (['за '.$number.' квартал 2026 года', 'за '.$number.'-й квартал 2026 года', 'в '.$roman.' квартале 2026 года',
                'за '.$ordinal.' квартал 2026 года', 'в '.$locative.' квартале 2026 года'] as $period) {
                $query = 'Сверь выполненные работы с графиком '.$period;
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => $query]]), $query);
                self::assertSame('Дом', AssistantProductionSchedulePeriod::withoutSuffix('Дом '.$period));
            }
        }
        foreach (['за 3 квартал', 'за 5 квартал 2026', 'за 2 и 3 кварталы 2026',
            'за 3 квартал 2026 и за октябрь 2026', 'за 3 квартал 2025 и 2026 годов'] as $period) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь работы с графиком '.$period]]), $period);
        }
        $message = 'Сверь выполненные работы с графиком за 3 квартал';
        $pending = AssistantProductionSchedulePeriod::pending(['request' => ['message' => $message]], [
            'replaced' => true, 'needs_clarification' => true, 'source_refs' => [[
                'entity_type' => AssistantProjectProductionScheduleReader::TYPE,
                '_read_arguments' => ['project_id' => 102, 'period_start' => null, 'period_end' => null],
            ]],
        ]);
        $clarification = AssistantProductionSchedulePeriod::clarification('2026', ['last_request' => ['message' => $message], 'pending_production_period' => $pending]);
        self::assertNotNull($clarification);
        self::assertSame(['project_id' => 102, 'period_start' => '2026-07-01', 'period_end' => '2026-09-30'],
            AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => $clarification['query']]]));
    }

    public function test_locative_months_with_explicit_year_are_resolved_and_clarifications_keep_the_month(): void
    {
        $months = ['январе', 'феврале', 'марте', 'апреле', 'мае', 'июне', 'июле', 'августе', 'сентябре', 'октябре', 'ноябре', 'декабре'];
        foreach ($months as $index => $month) {
            $start = sprintf('2026-%02d-01', $index + 1);
            $end = (new \DateTimeImmutable($start))->format('Y-m-t');
            foreach (['в', 'во'] as $preposition) {
                $message = 'Сверь выполненные работы с графиком '.$preposition.' '.$month.' 2026 года';
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => $message]]), $message);
            }
        }
        $message = 'Сверь выполненные работы с графиком в октябре';
        $pending = AssistantProductionSchedulePeriod::pending(['request' => ['message' => $message]], [
            'replaced' => true, 'needs_clarification' => true, 'source_refs' => [[
                'entity_type' => AssistantProjectProductionScheduleReader::TYPE,
                '_read_arguments' => ['project_id' => 102, 'period_start' => null, 'period_end' => null],
            ]],
        ]);
        $clarification = AssistantProductionSchedulePeriod::clarification('2026', ['last_request' => ['message' => $message], 'pending_production_period' => $pending]);
        self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'],
            AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => $clarification['query']]]));
    }

    public function test_ranges_keep_boundaries_and_unknown_periods_never_become_all_time(): void
    {
        self::assertSame('ЖК на 8 Марта', AssistantProductionSchedulePeriod::withoutSuffix('ЖК на 8 Марта'));
        foreach ([
            'Сверь выполненные работы с графиком за октябрь 2026' => ['2026-10-01', '2026-10-31'],
            'Сверь выполненные работы с графиком с 2026-10-01 по 2026-10-15' => ['2026-10-01', '2026-10-15'],
            'Сверь выполненные работы с графиком с 01.10.2026 по 15.10.2026' => ['2026-10-01', '2026-10-15'],
            'Сверь выполненные работы с графиком за октябрь' => [null, null],
            'Сверь выполненные работы с графиком за октябрь и ноябрь 2026' => [null, null],
            'Сверь выполненные работы с графиком с 1 по 15 октября 2026' => [null, null],
            'Сверь выполненные работы с графиком по 15 октября 2026' => [null, null],
            'Сверь выполненные работы с графиком с 31.02.2026 по 15.03.2026' => [null, null],
        ] as $query => [$start, $end]) {
            self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => $query]]), $query);
        }
        self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102, ['request' => [
            'message' => 'Сверь выполненные работы на текущем объекте с графиком',
        ]]));
    }

    public function test_scope_rejects_missing_invalid_reversed_and_extra_dates(): void
    {
        foreach ([['project_id' => 102], ['project_id' => 102, 'period_start' => null, 'period_end' => null],
            ['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']] as $arguments) {
            self::assertTrue(AssistantProductionSchedulePeriod::validArguments($arguments));
        }
        foreach ([['project_id' => 0], ['project_id' => '102'], ['project_id' => 102, 'period_start' => '2026-10-01'],
            ['project_id' => 102, 'period_start' => '2026-02-30', 'period_end' => '2026-03-31'],
            ['project_id' => 102, 'period_start' => '2026-11-01', 'period_end' => '2026-10-31'],
            ['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'organization_id' => 38]] as $arguments) {
            self::assertFalse(AssistantProductionSchedulePeriod::validArguments($arguments));
        }
    }

    public function test_verified_period_clarification_resumes_the_original_project_and_clears_stale_replies(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Северный дом» за октябрь';
        $plan = ['request' => ['message' => $message]];
        $verified = ['replaced' => true, 'needs_clarification' => true, 'source_refs' => [[
            'entity_type' => AssistantProjectProductionScheduleReader::TYPE,
            '_read_arguments' => ['project_id' => 102, 'period_start' => null, 'period_end' => null],
        ]]];
        $pending = AssistantProductionSchedulePeriod::pending($plan, $verified);
        self::assertNotNull($pending);
        $context = ['last_request' => $plan['request'], 'pending_production_period' => $pending];
        foreach (['2026', '2026 год', 'за 2026 год'] as $reply) {
            $clarification = AssistantProductionSchedulePeriod::clarification($reply, $context);
            self::assertSame('Сверь выполненные работы с графиком проекта текущий за октябрь 2026', $clarification['query']);
            self::assertSame($clarification['query'], AssistantProjectClarificationIntent::query($reply, [], $context));
            $payload = AssistantProductionSchedulePeriod::resumePayload(['context' => ['entity_refs' => [
                ['type' => 'project', 'id' => 104], ['type' => 'estimate', 'id' => 5],
            ]]], $clarification);
            $resolvedPlan = ['request' => ['message' => $clarification['query']] + $payload];
            self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'], AssistantRequiredProjectRead::fromPlan($resolvedPlan)['arguments']);
            self::assertSame([['type' => 'project', 'id' => 102]], $payload['context']['entity_references']);
            self::assertSame(['type' => 'estimate', 'id' => 5], $payload['context']['entity_refs'][0]);
        }
        $dates = AssistantProductionSchedulePeriod::clarification('с 2026-10-01 по 2026-10-15', $context);
        $override = $dates + ['message_sha256' => hash('sha256', $dates['query'])];
        $plan = ['request' => ['message' => $dates['query']], 'production_period_override' => $override];
        self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15'], AssistantProductionSchedulePeriod::arguments(102, $plan));
        ksort($plan['production_period_override']);
        self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15'], AssistantProductionSchedulePeriod::arguments(102, $plan));
        $saved = ['last_request' => $plan['request'] + ['production_period_override' => $override]];
        self::assertSame($dates, AssistantProductionSchedulePeriod::clarification('Покажи подробнее', $saved));
        $saved['last_request']['message'] = 'Другой вопрос';
        self::assertNull(AssistantProductionSchedulePeriod::clarification('Покажи подробнее', $saved));
        self::assertSame('2026', AssistantProjectClarificationIntent::query('2026', ['allow_actions' => true], $context));
        self::assertNull(AssistantProductionSchedulePeriod::clarification('Какие платежи просрочены?', $context));
        self::assertNull(AssistantProductionSchedulePeriod::clarification('Оплати счета', $context));
        $context['last_request']['message'] = 'Новый вопрос';
        self::assertNull(AssistantProductionSchedulePeriod::clarification('2026', $context));
        $verified['replaced'] = false;
        self::assertNull(AssistantProductionSchedulePeriod::pending($plan, $verified));
    }
}
