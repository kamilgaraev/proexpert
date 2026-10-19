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
    public function test_abbreviated_duration_units_keep_dates_named_scope_and_literal_priority(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-20 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за последние 6 мес.' => ['2026-04-20', '2026-10-20'], 'за последние 6 мес' => ['2026-04-20', '2026-10-20'],
                '6 мес.' => ['2026-04-20', '2026-10-20'], 'за 2 нед.' => ['2026-10-06', '2026-10-20'],
                'за крайние 2 нед.' => ['2026-10-06', '2026-10-20'], 'за последние две нед.' => ['2026-10-06', '2026-10-20'],
                'за 10 дн.' => ['2026-10-10', '2026-10-20'], 'за нед.' => ['2026-10-13', '2026-10-20'],
                'за последний мес.' => ['2026-09-20', '2026-10-20'], 'за прошлый мес.' => ['2026-09-01', '2026-09-30'],
                'на текущей нед.' => ['2026-10-19', '2026-10-20'], 'за текущий мес.' => ['2026-10-01', '2026-10-20']] as $phrase => [$start, $end]) {
                $this->assertPeriodScope($phrase, $start, $end);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_abbreviated_units_do_not_hide_unsupported_or_mixed_periods(): void
    {
        foreach (['за последние 100 мес.', 'за последние сто нед.', 'за 1.5 нед.', 'за несколько дн.',
            'через 2 нед.', 'за будущий мес.', 'за последние 6 мес. и за октябрь 2026'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null], AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
        }
        self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102,
            ['request' => ['message' => 'Сверь выполненные работы с месячным и недельным графиком']]));
    }

    public function test_calendar_half_years_require_dates_instead_of_expanding_to_the_whole_year(): void
    {
        foreach (['за первое полугодие 2026', 'за второе полугодие 2026', 'в первом полугодии 2026 года',
            'во втором полугодии 2026 года', 'первое полугодие 2026', 'второе полугодие 2026',
            'за 1-е полугодие 2026', 'за II полугодие 2026', 'за первое полугодие', 'за третье полугодие 2026',
            'с первого полугодия 2026', 'на протяжении второго полугодия 2026 года',
            'за первое полугодие 2026 и за октябрь 2026'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $plan['request']['message'] = 'Сверь выполненные работы с графиком проекта «Северный дом '.$phrase.'»';
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
        self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102,
            ['request' => ['message' => 'Сверь выполненные работы с полугодовым графиком']]));
        self::assertSame('Дом с полугодовым графиком', AssistantProductionSchedulePeriod::withoutSuffix('Дом с полугодовым графиком'));
    }

    public function test_calendar_year_determiners_are_removed_with_the_whole_period_after_exact_lookup(): void
    {
        foreach (['весь 2026 год', 'полный 2026 год', 'целый 2026 год', 'календарный 2026 год', 'полный календарный 2026 год',
            'весь 2026', 'весь 2026 г.', 'во всём 2026 году', 'за полный 2026 год', 'на протяжении полного 2026 года'] as $phrase) {
            $this->assertPeriodScope($phrase, '2026-01-01', '2026-12-31');
        }
    }

    public function test_calendar_month_determiners_are_removed_with_the_whole_period_after_exact_lookup(): void
    {
        foreach (['весь октябрь 2026', 'полный октябрь 2026', 'целый октябрь 2026', 'календарный октябрь 2026',
            'полный календарный октябрь 2026', 'за полный октябрь 2026', 'во всём октябре 2026', 'весь февраль 2026'] as $phrase) {
            $february = str_contains($phrase, 'февраль');
            $this->assertPeriodScope($phrase, $february ? '2026-02-01' : '2026-10-01', $february ? '2026-02-28' : '2026-10-31');
        }
    }

    private function assertPeriodScope(string $phrase, string $start, string $end): void
    {
        $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
        self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
            ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
        $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
        $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
            'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
        self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
        $literalPlan = $plan;
        $literalPlan['request']['message'] = 'Сверь выполненные работы с графиком проекта «Северный дом '.$phrase.'»';
        $literal = AssistantNamedProjectReadScope::bind($literalPlan, static function (array $arguments) use ($phrase): array {
            self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

            return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
        });
        self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
    }

    public function test_bare_calendar_years_keep_dates_named_scope_and_literal_project_priority(): void
    {
        foreach (['2026', '1900', '1999', '2000', '2024', '2099'] as $year) {
            foreach ([$year.' год', $year.' года', $year.' году', $year.' г.', $year.' г', $year,
                'в течение '.$year.' года', 'на протяжении '.$year.' года'] as $phrase) {
                $expected = ['project_id' => 102, 'period_start' => $year.'-01-01', 'period_end' => $year.'-12-31'];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
                $literalPlan = $plan;
                $literalPlan['request']['message'] = 'Сверь выполненные работы с графиком проекта «Северный дом '.$phrase.'»';
                $literal = AssistantNamedProjectReadScope::bind($literalPlan, static function (array $arguments) use ($phrase): array {
                    self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                    return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
                });
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
            }
        }
        foreach (['2025 год и 2026 год', '2026 год и за октябрь', 'до 2026 года', 'от 2026 года', 'по 2026 год', 'с 2026 года'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null], AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase));
        }
    }

    public function test_adjectival_relative_days_keep_day_bounds_named_scope_and_literal_priority(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 21:30:00', 'UTC'));
        try {
            foreach (['сегодня' => '2026-10-20', 'вчера' => '2026-10-19', 'позавчера' => '2026-10-18'] as $stem => $date) {
                foreach ([$stem.'шний день', 'за '.$stem.'шний день', 'в течение '.$stem.'шнего дня', 'на протяжении '.$stem.'шних суток'] as $phrase) {
                    $expected = ['project_id' => 102, 'period_start' => $date, 'period_end' => $date];
                    self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                        ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                    $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                        'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                    $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                    self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
                    $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                        self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                        return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
                    });
                    self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
                }
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_adjectival_future_historical_and_mixed_days_require_dates_while_current_snapshot_stays_unfiltered(): void
    {
        $phrases = ['на вчерашний день', 'по состоянию на позавчерашний день', 'за октябрь 2026 на вчерашний день',
            'до вчерашнего дня', 'за сегодняшний день и за вчерашний день'];
        foreach (['завтра', 'послезавтра'] as $stem) {
            array_push($phrases, 'за '.$stem.'шний день', $stem.'шний день', 'в течение '.$stem.'шнего дня', 'на протяжении '.$stem.'шних суток');
        }
        foreach ($phrases as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
        }
        foreach (['на сегодняшний день', 'по состоянию на сегодняшний день', 'на сегодняшние сутки', 'по состоянию на сегодняшние сутки'] as $phrase) {
            self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase), $phrase);
        }
    }

    public function test_unsupported_date_boundaries_never_extend_to_today_or_all_time(): void
    {
        foreach (['с начала года до 01.10.2026', 'с начала проекта до 2026-10-01',
            'с начала проекта до 01-10-2026', 'до 01.10.2026', 'от 01.10.2026', 'до вчера', 'от сегодня', 'по вчера', 'с вчера',
            'с начала квартала до 1 октября 2026', 'с начала года до октября 2026'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            $literalPlan = $plan;
            $literalPlan['request']['message'] = 'Сверь выполненные работы с графиком проекта «Северный дом '.$phrase.'»';
            $literal = AssistantNamedProjectReadScope::bind($literalPlan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_future_relative_days_require_clarification_and_keep_literal_priority(): void
    {
        foreach (['завтра', 'послезавтра', 'за завтра', 'за послезавтра', 'завтра и вчера', 'за октябрь 2026 завтра'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        }
        self::assertSame('Завтраково', AssistantProductionSchedulePeriod::withoutSuffix('Завтраково'));
    }

    public function test_future_calendar_selectors_require_dates_and_keep_literal_project_priority(): void
    {
        foreach (['будущий месяц', 'будущий год', 'будущий квартал', 'будущий день', 'будущая неделя',
            'будущую неделю', 'за будущий месяц', 'в будущем месяце', 'в будущем году', 'на будущей неделе',
            'следующий месяц', 'следующая неделя', 'следующую неделю', 'на следующей неделе',
            'будущие два месяца', 'следующие 3 недели', 'будущий час', 'за сентябрь 2026 и будущая неделя'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_supported_neutral_details_keep_period_without_rewriting_new_financial_intent(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Северный дом» с 2026-10-01 по 2026-10-10';
        $dates = ['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-10'];
        $saved = ['last_request' => ['message' => $message,
            'production_period_override' => $dates + ['message_sha256' => hash('sha256', $message)]]];
        $service = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class))->newInstanceWithoutConstructor();
        $matcher = new \ReflectionMethod($service, 'isDetailContinuation');
        foreach (['а подробнее', 'давай детальнее', 'можно подробнее', 'расскажи подробнее', 'поясни это',
            'разверни по этому вопросу', 'ещё подробнее', 'Покажи подробнее!', 'Покажите подробнее',
            'точнее', 'давай конкретнее, а не около', 'какие позиции', 'давай подробнее', 'детальнее',
            'можно детальнее', 'покажи детали', 'подробнее', 'подробней', 'поясни', 'распиши',
            'расскажи детальнее', 'расшифруй', 'разверни', 'что именно', 'еще подробнее',
            "  МОЖНО\nПОДРОБНЕЕ?!  "] as $reply) {
            self::assertTrue($matcher->invoke($service, $reply), $reply);
            self::assertSame(['query' => $message] + $dates, AssistantProductionSchedulePeriod::clarification($reply, $saved), $reply);
            self::assertSame($message, AssistantProjectClarificationIntent::query($reply, [], $saved));
            self::assertSame($reply, AssistantProjectClarificationIntent::query($reply, ['allow_actions' => true], $saved));
            $stale = $saved;
            $stale['last_request']['message'] = 'Другой вопрос';
            self::assertNull(AssistantProductionSchedulePeriod::clarification($reply, $stale));
        }
        foreach (['по деньгам', 'надо по деньгам', 'какие это позиции по смете', 'По деньгам!'] as $reply) {
            self::assertTrue($matcher->invoke($service, $reply), $reply);
            self::assertNull(AssistantProductionSchedulePeriod::clarification($reply, $saved));
            self::assertSame($reply, AssistantProjectClarificationIntent::query($reply, [], $saved));
        }
        foreach (['Оплати счета', 'Какие платежи просрочены?', str_repeat('а ', 100).'подробнее'] as $reply) {
            self::assertNull(AssistantProductionSchedulePeriod::clarification($reply, $saved));
        }
    }

    public function test_prepositionless_calendar_units_match_explicit_periods_and_named_scope(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['прошлый месяц' => 'за прошлый месяц', 'предыдущий месяц' => 'за предыдущий месяц',
                'текущий месяц' => 'за текущий месяц', 'этот месяц' => 'за этот месяц', 'позапрошлый месяц' => 'за позапрошлый месяц',
                'прошлый год' => 'за прошлый год', 'предыдущий год' => 'за предыдущий год', 'текущий год' => 'за текущий год',
                'позапрошлый год' => 'за позапрошлый год', 'прошлая неделя' => 'за прошлую неделю',
                'предыдущая неделя' => 'за предыдущую неделю', 'текущая неделя' => 'за текущую неделю', 'эта неделя' => 'за эту неделю',
                'позапрошлая неделя' => 'за позапрошлую неделю', 'прошлый день' => 'за прошлый день',
                'предыдущий день' => 'за предыдущий день', 'текущий день' => 'за текущий день', 'позапрошлый день' => 'за позапрошлый день'] as $phrase => $explicit) {
                $expected = AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$explicit]]);
                self::assertNotNull($expected['period_start'] ?? null, $explicit);
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_prepositionless_relative_quarters_keep_calendar_bounds_and_named_project(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['прошлый квартал' => ['2026-07-01', '2026-09-30'], 'предыдущий квартал' => ['2026-07-01', '2026-09-30'],
                'текущий квартал' => ['2026-10-01', '2026-10-19'], 'этот квартал' => ['2026-10-01', '2026-10-19'],
                'позапрошлый квартал' => ['2026-04-01', '2026-06-30']] as $phrase => [$start, $end]) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
            foreach (['прошлый квартал 2025', 'прошлый квартал и вчера', 'следующий квартал'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_historical_snapshot_does_not_turn_into_physical_day_filter(): void
    {
        foreach (['по состоянию на вчера', 'по состоянию на позавчера', 'по состоянию на завтра',
            'по состоянию на 2026-10-01', 'по состоянию на 01.10.2026', 'за октябрь 2025 по состоянию на вчера',
            'на вчера', 'на позавчера', 'на завтра', 'на послезавтра', 'на 2026-10-01', 'на 01.10.2026',
            'за октябрь 2025 на вчера'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        }
    }

    public function test_current_snapshot_date_does_not_replace_physical_work_period(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['на данный момент', 'на настоящий момент', 'на сегодня', 'на сейчас', 'на текущий момент', 'на текущий день', 'на текущую дату',
                'по состоянию на сегодня', 'по состоянию на сегодняшний день', 'на сегодняшний день', 'по состоянию на сейчас',
                'по состоянию на данный момент', 'по состоянию на настоящий момент',
                'по состоянию на текущий момент', 'по состоянию на текущий день', 'по состоянию на текущую дату'] as $phrase) {
                self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 102], AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
            foreach (['за вчера по состоянию на сегодня' => ['2026-10-18', '2026-10-18'],
                'за сегодня по состоянию на сегодня' => ['2026-10-19', '2026-10-19'],
                'за вчера на сегодня' => ['2026-10-18', '2026-10-18'],
                'за сегодня на сегодня' => ['2026-10-19', '2026-10-19'],
                'за вчера на данный момент' => ['2026-10-18', '2026-10-18'],
                'за сегодня на настоящий момент' => ['2026-10-19', '2026-10-19'],
                'с 2025-12-01 по 2026-01-01 на сегодня' => ['2025-12-01', '2026-01-01'],
                'с 2025-12-01 по 2026-01-01 по состоянию на сегодня' => ['2025-12-01', '2026-01-01']] as $phrase => [$start, $end]) {
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => $end],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_subday_periods_require_date_bounds_without_silent_all_time_fallback(): void
    {
        foreach (['за последние 24 часа', 'последние 24 часа', 'за час', 'за последние три часа', 'за 90 минут',
            'за последнюю минуту', 'за 30 секунд', 'за текущий час', 'за 120 часов',
            'в течение 6 часов', 'на протяжении 6 минут'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        }
    }

    public function test_bare_single_dates_require_dates_without_silent_all_time_and_keep_literal_priority(): void
    {
        foreach (['01.10.2026', '1.10.2026', '01/10/2026', '01-10-2026', '2026-10-01',
            '31.02.2026', '2026-13-01', '01.10.2026 и 01.11.2026'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_unsupported_integer_durations_require_dates_and_keep_literal_project_priority(): void
    {
        $phrases = ['последние 100 месяцев', 'в течение 100 месяцев', 'на протяжении 100 недель', 'в течение 999 дней', 'на протяжении 2026 дней',
            '2026 дней', 'последние 2026 года', 'последние 999999999999999999999999999999999 месяцев'];
        foreach (['', 'последние', 'крайние', 'прошедшие', 'минувшие', 'предыдущие'] as $modifier) {
            foreach (['дней', 'суток', 'недель', 'месяцев', 'лет', 'кварталов'] as $unit) {
                $phrases[] = trim($modifier.' 100 '.$unit);
            }
        }
        foreach (array_unique($phrases) as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_supported_two_digit_duration_boundary_keeps_shared_resolver_dates(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['99 месяцев', 'последние 99 месяцев', 'за 99 месяцев', 'в течение 99 месяцев', 'на протяжении 99 месяцев'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => '2018-07-19', 'period_end' => '2026-10-19'],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$phrase));
            }
            foreach (['одиннадцать' => '2025-11-19', 'двенадцать' => '2025-10-19'] as $number => $start) {
                self::assertSame(['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$number.' месяцев']]));
                self::assertSame('Северный дом', AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$number.' месяцев'));
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_unsupported_word_and_compound_counts_require_dates_and_keep_literal_priority(): void
    {
        foreach (['сто месяцев', 'последние двадцать месяцев', 'тринадцать месяцев', 'тридцать дней', 'сорок недель',
            'пятьдесят дней', 'девяносто лет', 'двести дней', 'пятьсот дней', 'тысяча месяцев', 'миллион дней',
            'двадцать пять месяцев', 'сто пятьдесят месяцев', '20 пять месяцев', 'два пять месяцев', '2 5 месяцев'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_duration_introducers_are_removed_with_the_whole_period_after_exact_lookup(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['в течение', 'на протяжении'] as $introducer) {
                foreach (['6 месяцев' => '2026-04-19', '2 недели' => '2026-10-05', 'три дня' => '2026-10-16',
                    'полгода' => '2026-04-19', 'последние 6 месяцев' => '2026-04-19'] as $duration => $start) {
                    $phrase = $introducer.' '.$duration;
                    $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'];
                    self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                        ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                    $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                        'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                    $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                    self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
                    $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                        self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                        return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
                    });
                    self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
                }
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_bare_named_month_with_year_keeps_calendar_bounds_and_literal_project_priority(): void
    {
        $cases = [];
        foreach ([10 => ['октябрь', 'октября', 'октябре'], 1 => ['январь', 'января', 'январе'],
            2 => ['февраль', 'февраля', 'феврале'], 3 => ['март', 'марта', 'марте'], 4 => ['апрель', 'апреля', 'апреле'],
            5 => ['май', 'мая', 'мае'], 6 => ['июнь', 'июня', 'июне'], 7 => ['июль', 'июля', 'июле'],
            8 => ['август', 'августа', 'августе'], 9 => ['сентябрь', 'сентября', 'сентябре'],
            11 => ['ноябрь', 'ноября', 'ноябре'], 12 => ['декабрь', 'декабря', 'декабре']] as $month => $forms) {
            $start = sprintf('2026-%02d-01', $month);
            foreach ($forms as $form) {
                $cases[$form.' 2026'] = [$start, (new \DateTimeImmutable($start))->format('Y-m-t')];
            }
        }
        $cases['октябрь 2026 года'] = $cases['октябрь 2026 г.'] = ['2026-10-01', '2026-10-31'];
        foreach ($cases as $phrase => [$start, $end]) {
            $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literalPlan = $plan;
            $literalPlan['request']['message'] = 'Сверь выполненные работы с графиком проекта «Северный дом '.$phrase.'»';
            $literal = AssistantNamedProjectReadScope::bind($literalPlan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
        foreach (['с октября 2026', 'до октября 2026', 'октябрь 2025 и 2026', 'октябрь 2026 и сентябрь 2026'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
        }
    }

    public function test_named_month_with_multiple_years_requires_dates_and_keeps_literal_name_priority(): void
    {
        foreach (['за октябрь 2025 и 2026', 'за октябрь 2025 или 2026', 'в октябре 2025–2026', 'за октябрь 2025, 2026'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        }
    }

    public function test_past_quarter_aliases_share_rolling_period_and_reject_ambiguous_years(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за крайний квартал', 'крайний квартал', 'за прошедший квартал', 'за минувший квартал', 'прошедший квартал', 'минувший квартал'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => '2026-07-19', 'period_end' => '2026-10-19'],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 102, 'period_start' => '2026-07-19', 'period_end' => '2026-10-19'], AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
            foreach (['за прошедший квартал 2026', 'за минувший квартал и вчера'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_prepositionless_durations_keep_resolver_semantics_and_named_scope(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['последние три месяца' => '2026-07-19', 'последний квартал' => '2026-07-19',
                'три месяца' => '2026-07-19', 'последний месяц' => '2026-09-19',
                'предыдущие две недели' => '2026-10-05', 'прошедшие два года' => '2024-10-19'] as $phrase => $start) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
            foreach (['последние недели', 'три квартала', 'последние три месяца и вчера', 'последний квартал 2026'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
            self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком, сколько дней просрочено?']]));
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_indefinite_durations_require_dates_and_preserve_literal_project_names(): void
    {
        foreach (['несколько месяцев', 'несколько недель', 'несколько лет', 'несколько дней', 'несколько суток',
            'несколько кварталов', 'последние несколько месяцев', 'за несколько месяцев', 'много лет', 'немного дней',
            'ряд месяцев', 'неизвестное количество дней', 'неопределённое количество недель',
            'в течение нескольких месяцев', 'на протяжении нескольких лет', 'несколько часов', 'несколько минут'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_noninteger_work_durations_require_dates_without_reusing_numeric_tails(): void
    {
        foreach (['за последние 1,5 рабочих месяца', 'последние 1.5 календарные недели',
            'за последние 1,5 месяца', 'за последние 1.5 месяца', 'за 1/2 года', 'за 1 1/2 месяца',
            'последние 1,5 месяца', '1.5 месяца', '.5 недели', ',5 недели', '-2 недели', '+2 недели',
            'за 1e3 месяца', 'за 1..5 месяца', 'в течение 0,5 года', 'на протяжении 1/2 года',
            'за полтора месяца', 'полторы недели', 'за половину месяца', 'за сентябрь 2026 и последние 1,5 месяца'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_qualified_durations_require_dates_and_preserve_literal_project_priority(): void
    {
        foreach (['за последние 6 календарных месяцев', 'последние 6 календарных месяцев',
            'за шесть календарных месяцев', '6 календарных месяцев', 'за 5 рабочих дней',
            'пять рабочих дней', 'за последние три полных месяца', 'последние полные недели',
            'в течение нескольких рабочих дней', 'на протяжении 2 календарных лет',
            'за последние 6 полных календарных месяцев', 'за сентябрь 2026 и 5 рабочих дней'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
    }

    public function test_explicit_ranges_with_year_suffix_are_not_fractional_durations(): void
    {
        foreach (['с 01.10.2026 по 10.10.2026 года', 'с 01/10/2026 по 10/10/2026 года',
            'с 01-10-2026 по 10-10-2026 года', 'с 2026-10-01 по 2026-10-10 года'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-10'],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
        }
    }

    public function test_relative_forward_offsets_require_dates_and_preserve_literal_project_names(): void
    {
        foreach (['через 1,5 месяца', 'через 1.5 месяца', 'через 0,5 года', 'через .5 недели', 'через ,5 недели',
            'через 1/2 года', 'через 1 1/2 месяца', 'спустя 2,5 недели', 'за сентябрь 2026 через 1.5 месяца',
            'через 1e3 месяца', 'через 1..5 месяца', 'через половину месяца',
            'через неделю', 'через месяц', 'через год', 'через квартал', 'через полгода', 'через два дня',
            'через 100 недель', 'через тринадцать дней', 'через двадцать один день', 'через несколько месяцев',
            'через неизвестное количество лет', 'через полтора года', 'через два с половиной месяца', 'через одну-две недели',
            'через три рабочих дня', 'через две календарные недели', 'через час', 'через тридцать минут',
            'спустя неделю', 'спустя пару месяцев', 'спустя несколько часов', 'через следующую неделю', 'за сентябрь 2026 через неделю'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
            });
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
        foreach (['Прокладка сетей через жилой квартал', 'Маршрут через географический квартал'] as $description) {
            self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком. '.$description]]));
            self::assertSame('Северный дом '.$description, AssistantProductionSchedulePeriod::withoutSuffix('Северный дом '.$description));
        }
    }

    public function test_current_snapshot_suffix_keeps_named_project_and_optional_work_period(): void
    {
        foreach (['сейчас', 'прямо сейчас', 'на сейчас', 'на прямо сейчас', 'по состоянию на сейчас', 'по состоянию на прямо сейчас',
            'в настоящий момент', 'в текущий момент', 'в данный момент', 'в этот момент', 'в настоящее время', 'в текущее время', 'в данное время',
            'на этот момент', 'на текущий момент', 'на данный момент', 'на настоящий момент', 'на настоящее время', 'на текущее время', 'на данное время'] as $snapshot) {
            foreach (['Сверь выполненные работы проекта Северный дом с графиком ', 'Сверь выполненные работы с графиком проекта Северный дом '] as $prefix) {
                foreach (['' => ['project_id' => 102], ' за сентябрь 2026' => ['project_id' => 102, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']] as $period => $expected) {
                    $plan = ['request' => ['message' => $prefix.$snapshot.$period,
                        'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                    $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                    self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $plan['request']['message']);
                    $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($prefix, $snapshot, $period): array {
                        self::assertSame(['query' => 'Северный дом'.(str_contains($prefix, 'дом с графиком') ? ' с графиком' : '').' '.$snapshot.$period,
                            'limit' => 2, 'name_match' => 'exact'], $arguments);

                        return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
                    });
                    self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
                }
            }
        }
    }

    public function test_feminine_week_durations_keep_named_scope_and_rolling_period(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['последняя неделя', 'крайняя неделя', 'прошедшая неделя', 'минувшая неделя',
                'последнюю неделю', 'крайнюю неделю', 'прошедшую неделю', 'минувшую неделю'] as $duration) {
                foreach (['', 'за '] as $prefix) {
                    $phrase = $prefix.$duration;
                    $expected = ['project_id' => 102, 'period_start' => '2026-10-12', 'period_end' => '2026-10-19'];
                    self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                        ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                    $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                        'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                    $bound = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                    self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($bound)['arguments'], $phrase);
                    $literal = AssistantNamedProjectReadScope::bind($plan, static function (array $arguments) use ($phrase): array {
                        self::assertSame(['query' => 'Северный дом '.$phrase, 'limit' => 2, 'name_match' => 'exact'], $arguments);

                        return ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['has_more' => false]];
                    });
                    self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
                }
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_counted_quarters_require_dates_and_preserve_named_project_priority(): void
    {
        foreach (['за три квартала', 'за последние три квартала', 'за предыдущие два квартала',
            'за один квартал', 'за последние одиннадцать кварталов', 'за несколько кварталов'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
        }
    }

    public function test_previous_counted_durations_preserve_words_named_scope_and_unsupported_amounts(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за предыдущие три месяца' => '2026-07-19', 'за предыдущие две недели' => '2026-10-05',
                'за предыдущие два года' => '2024-10-19', 'за предыдущие двенадцать дней' => '2026-10-07',
                'за предыдущие 3 месяца' => '2026-07-19'] as $phrase => $start) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-19'];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            }
            foreach (['за предыдущие месяцы', 'за предыдущие несколько месяцев', 'за неизвестное количество дней',
                'за предыдущие три месяца и вчера'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_dmy_range_clarification_keeps_original_query_project_and_filters(): void
    {
        $message = 'Сверь выполненные работы с графиком проекта «Северный дом» за октябрь';
        $context = ['last_request' => ['message' => $message], 'pending_production_period' => AssistantProductionSchedulePeriod::pending(
            ['request' => ['message' => $message]], ['replaced' => true, 'needs_clarification' => true, 'source_refs' => [[
                'entity_type' => AssistantProjectProductionScheduleReader::TYPE,
                '_read_arguments' => ['project_id' => 102, 'period_start' => null, 'period_end' => null],
            ]]])];
        foreach (['с 01.10.2026 по 15.10.2026', 'с 01/10/2026 по 15/10/2026', 'с 01-10-2026 по 15-10-2026',
            'со 01.10.2026 по 15.10.2026', 'за период с 01.10.2026 по 15.10.2026', 'с 2026-10-01 по 2026-10-15'] as $reply) {
            $dates = AssistantProductionSchedulePeriod::clarification($reply, $context);
            self::assertNotNull($dates, $reply);
            self::assertSame(['query' => $context['pending_production_period']['period_query'], 'project_id' => 102,
                'period_start' => '2026-10-01', 'period_end' => '2026-10-15'], $dates, $reply);
            self::assertSame($dates['query'], AssistantProjectClarificationIntent::query($reply, [], $context));
            $payload = AssistantProductionSchedulePeriod::resumePayload(['context' => ['entity_refs' => [['type' => 'project', 'id' => 104]],
                'filters' => ['status' => 'confirmed']]], $dates);
            self::assertSame(['status' => 'confirmed'], $payload['context']['filters']);
            $plan = ['request' => ['message' => $dates['query']] + $payload,
                'production_period_override' => $dates + ['message_sha256' => hash('sha256', $dates['query'])]];
            self::assertSame(['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15'], AssistantRequiredProjectRead::fromPlan($plan)['arguments']);
        }
        foreach (['с 31.02.2026 по 15.03.2026', 'с 15.10.2026 по 01.10.2026', 'с 01.10.2026',
            'с 01.10.2026 по 15.10.2026 и покажи камеры', 'Оплати счета'] as $reply) {
            self::assertNull(AssistantProductionSchedulePeriod::clarification($reply, $context));
        }
        $context['last_request']['message'] = 'Новый вопрос';
        self::assertNull(AssistantProductionSchedulePeriod::clarification('с 01.10.2026 по 15.10.2026', $context));
    }

    public function test_whole_relative_calendar_units_resolve_full_boundaries_and_preserve_literal_names(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-01 00:30:00', 'Europe/Moscow'));
        try {
            foreach (['за весь текущий год' => ['2026-01-01', '2026-12-31'], 'за весь прошлый год' => ['2025-01-01', '2025-12-31'],
                'за весь позапрошлый год' => ['2024-01-01', '2024-12-31'], 'за весь прошлый квартал' => ['2025-10-01', '2025-12-31'],
                'во всём текущем квартале' => ['2026-01-01', '2026-03-31'], 'за весь текущий месяц' => ['2026-01-01', '2026-01-31'],
                'за весь предыдущий месяц' => ['2025-12-01', '2025-12-31'], 'за всю текущую неделю' => ['2025-12-29', '2026-01-04'],
                'за всю прошлую неделю' => ['2025-12-22', '2025-12-28'], 'за весь текущий день' => ['2026-01-01', '2026-01-01']] as $phrase => [$start, $end]) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
                $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                    'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
                self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
            }
            foreach (['за весь будущий месяц', 'за все месяцы', 'за всю неделю', 'за весь текущий год и вчера'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_all_time_suffix_is_separate_from_project_name_after_empty_exact_lookup(): void
    {
        foreach (['за весь период', 'за всё время', 'за все время'] as $phrase) {
            $query = 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase;
            $seen = [];
            $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => $query,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]], static function (array $arguments) use (&$seen): array {
                    $seen[] = $arguments;

                    return ['status' => 'success', 'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]];
                });
            self::assertSame(['Северный дом '.$phrase, 'Северный дом'], array_column($seen, 'query'));
            self::assertSame(['exact', 'exact'], array_column($seen, 'name_match'));
            self::assertSame(['project_id' => 102], AssistantRequiredProjectRead::fromPlan($resolved)['arguments']);
            $literal = AssistantNamedProjectReadScope::bind(['request' => ['message' => $query]], static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments']);
            self::assertSame(['project_id' => 102], AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]));
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase.' и вчера']]));
        }
    }

    public function test_whole_calendar_modifiers_keep_periods_and_literal_project_name_priority(): void
    {
        foreach (['за весь 2026 год' => ['2026-01-01', '2026-12-31'], 'за весь октябрь 2026' => ['2026-10-01', '2026-10-31'],
            'во всём октябре 2026' => ['2026-10-01', '2026-10-31'], 'на весь 3 квартал 2026' => ['2026-07-01', '2026-09-30']] as $phrase => [$start, $end]) {
            $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            $resolved = AssistantNamedProjectReadScope::bind($plan, static fn (array $arguments): array => ['status' => 'success',
                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            $literal = AssistantNamedProjectReadScope::bind($plan, static fn (): array => ['status' => 'success',
                'results' => [['id' => 95]], 'result_window' => ['has_more' => false]]);
            self::assertSame(['project_id' => 95], AssistantRequiredProjectRead::fromPlan($literal)['arguments'], $phrase);
        }
        foreach (['за весь октябрь', 'за весь год', 'за весь месяц', 'за весь 2026 год и вчера'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
        }
    }

    public function test_explicit_period_introducers_belong_to_the_single_date_range(): void
    {
        foreach (['за период с 2026-10-01 по 2026-10-15', 'в период с 01.10.2026 по 15.10.2026',
            'за весь период с 01/10/2026 по 15/10/2026', 'за период со 01-10-2026 по 15-10-2026'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15'];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase]],
                static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
        }
        foreach (['с 2026-10-01 по 2026-10-15 и за период',
            'за период с 2026-10-01 по 2026-10-15 и с 2026-09-01 по 2026-09-15'] as $phrase) {
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
        }
    }

    public function test_numbered_quarters_resolve_relative_years_and_preserve_named_scope(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-01 00:30:00', 'Europe/Moscow'));
        try {
            foreach (['за первый квартал текущего года' => ['2026-01-01', '2026-03-31'],
                'за 3 квартал прошлого года' => ['2025-07-01', '2025-09-30'],
                'за IV квартал предыдущего года' => ['2025-10-01', '2025-12-31'],
                'во втором квартале этого года' => ['2026-04-01', '2026-06-30'],
                'за четвёртый квартал позапрошлого года' => ['2024-10-01', '2024-12-31']] as $phrase => [$start, $end]) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]],
                    static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_multiple_explicit_ranges_never_silently_select_the_first_period(): void
    {
        foreach (['с 2026-10-01 по 2026-10-15 и с 2026-09-01 по 2026-09-15',
            'с 01.10.2026 по 15.10.2026 и с 01.09.2026 по 15.09.2026',
            'с 01/10/2026 по 15/10/2026 и с 01-09-2026 по 15-09-2026',
            'с 2026-10-01 по 2026-10-15 и с 01.09.2026 по 15.09.2026',
            'с 2026-10-01 по 2026-10-15 и с 2026-10-01 по 2026-10-15',
            'с 2026-10-01 по 2026-10-15 и за сентябрь 2026'] as $phrase) {
            $expected = ['project_id' => 102, 'period_start' => null, 'period_end' => null];
            self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase]],
                static fn (array $arguments): array => ['status' => 'success',
                    'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
            self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
        }
    }

    public function test_calendar_day_adjectives_resolve_like_today_yesterday_and_day_before(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-01-01 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['за этот день' => '2026-01-01', 'за текущий день' => '2026-01-01',
                'за прошлый день' => '2025-12-31', 'за предыдущий день' => '2025-12-31',
                'за позапрошлый день' => '2025-12-30'] as $phrase => $date) {
                $expected = ['project_id' => 102, 'period_start' => $date, 'period_end' => $date];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase]],
                    static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком за прошлый день и за прошлый месяц']]));
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_two_calendar_quarters_ago_resolves_full_quarter_and_named_scope(): void
    {
        foreach (['2026-01-01' => ['2025-07-01', '2025-09-30'], '2026-10-19' => ['2026-04-01', '2026-06-30']] as $today => [$start, $end]) {
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($today.' 10:00:00', 'Europe/Moscow'));
            try {
                foreach (['за позапрошлый квартал', 'в позапрошлом квартале', 'на позапрошлый квартал'] as $phrase) {
                    $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                    self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                        ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                    $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                        'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]],
                        static fn (array $arguments): array => ['status' => 'success',
                            'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                    self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                }
            } finally {
                \Carbon\CarbonImmutable::setTestNow();
            }
        }
    }

    public function test_two_calendar_months_or_years_ago_resolves_full_period_and_named_scope(): void
    {
        foreach (['2026-01-01' => ['месяц' => ['2025-11-01', '2025-11-30'], 'год' => ['2024-01-01', '2024-12-31']],
            '2026-10-19' => ['месяц' => ['2026-08-01', '2026-08-31'], 'год' => ['2024-01-01', '2024-12-31']]] as $today => $periods) {
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($today.' 10:00:00', 'Europe/Moscow'));
            try {
                foreach ($periods as $unit => [$start, $end]) {
                    foreach (['за позапрошлый '.$unit, 'в позапрошлом '.($unit === 'год' ? 'году' : 'месяце')] as $phrase) {
                        $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $end];
                        self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                            ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                        $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                            'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]],
                            static fn (array $arguments): array => ['status' => 'success',
                                'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                        self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                    }
                }
                foreach (['за позапрошлый месяц и вчера', 'за позапрошлый год и за позапрошлый квартал', 'за позапрошлый месяц 2025'] as $phrase) {
                    self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                        AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                }
            } finally {
                \Carbon\CarbonImmutable::setTestNow();
            }
        }
    }

    public function test_calendar_start_includes_quarters_and_days_with_calendar_boundaries(): void
    {
        foreach (['2026-01-01' => ['с начала текущего квартала' => '2026-01-01', 'с начала прошлого квартала' => '2025-10-01',
            'с начала позапрошлого квартала' => '2025-07-01', 'с начала дня' => '2026-01-01'],
            '2026-10-21' => ['с начала квартала' => '2026-10-01', 'с начала предыдущего квартала' => '2026-07-01',
                'с начала текущего дня' => '2026-10-21', 'с начала суток' => '2026-10-21']] as $today => $periods) {
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($today.' 10:00:00', 'Europe/Moscow'));
            try {
                foreach ($periods as $phrase => $start) {
                    $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => $today];
                    self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                        ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                    $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                        'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]],
                        static fn (array $arguments): array => ['status' => 'success',
                            'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                    self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
                }
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком с начала квартала и за прошлый месяц']]));
            } finally {
                \Carbon\CarbonImmutable::setTestNow();
            }
        }
    }

    public function test_calendar_start_resolves_explicit_and_previous_periods_through_today(): void
    {
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-21 10:00:00', 'Europe/Moscow'));
        try {
            foreach (['с начала 2026 года' => '2026-01-01', 'с начала 2025 года' => '2025-01-01',
                'с начала прошлого года' => '2025-01-01', 'с начала предыдущего года' => '2025-01-01',
                'с начала позапрошлого года' => '2024-01-01', 'с начала 2024 года по текущий день' => '2024-01-01',
                'с начала прошлого месяца' => '2026-09-01', 'с начала предыдущего месяца' => '2026-09-01',
                'с начала позапрошлого месяца' => '2026-08-01', 'с начала прошлой недели' => '2026-10-12',
                'с начала предыдущей недели' => '2026-10-12', 'с начала позапрошлой недели' => '2026-10-05',
                'с начала текущей недели по сегодня' => '2026-10-19'] as $phrase => $start) {
                $expected = ['project_id' => 102, 'period_start' => $start, 'period_end' => '2026-10-21'];
                self::assertSame($expected, AssistantProductionSchedulePeriod::arguments(102,
                    ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
                $resolved = AssistantNamedProjectReadScope::bind(['request' => ['message' => 'Сверь выполненные работы с графиком проекта Северный дом '.$phrase,
                    'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]],
                    static fn (array $arguments): array => ['status' => 'success',
                        'results' => $arguments['query'] === 'Северный дом' ? [['id' => 102]] : [], 'result_window' => ['has_more' => false]]);
                self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($resolved)['arguments'], $phrase);
            }
            foreach (['с начала 2027 года', 'с начала 2026 месяца', 'с начала следующего года',
                'с начала 2025 года и с начала 2026 года', 'с начала прошлого года и вчера'] as $phrase) {
                self::assertSame(['project_id' => 102, 'period_start' => null, 'period_end' => null],
                    AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком '.$phrase]]), $phrase);
            }
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2024-03-31 10:00:00', 'Europe/Moscow'));
            self::assertSame(['project_id' => 102, 'period_start' => '2024-02-01', 'period_end' => '2024-03-31'],
                AssistantProductionSchedulePeriod::arguments(102, ['request' => ['message' => 'Сверь выполненные работы с графиком с начала прошлого месяца']]));
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_calendar_start_periods_resolve_without_inheriting_project_start(): void
    {
        foreach (['2026-10-21' => ['года' => '2026-01-01', 'месяца' => '2026-10-01', 'недели' => '2026-10-19'],
            '2026-01-01' => ['года' => '2026-01-01', 'месяца' => '2026-01-01', 'недели' => '2025-12-29']] as $today => $periods) {
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable($today.' 10:00:00', 'Europe/Moscow'));
            try {
                foreach ($periods as $unit => $start) {
                    foreach ($unit === 'недели' ? ['с начала ', 'с начала текущей ', 'с начала этой '] : ['с начала ', 'с начала текущего ', 'с начала этого '] as $prefix) {
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
