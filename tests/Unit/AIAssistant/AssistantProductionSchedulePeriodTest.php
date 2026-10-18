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
