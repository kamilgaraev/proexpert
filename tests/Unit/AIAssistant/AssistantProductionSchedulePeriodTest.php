<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantProductionSchedulePeriod;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectClarificationIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;
use PHPUnit\Framework\TestCase;

final class AssistantProductionSchedulePeriodTest extends TestCase
{
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
            ['project_id' => 102, 'period_start' => null, 'period_end' => '2026-10-31'],
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
