<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use PHPUnit\Framework\TestCase;

final class AssistantRequiredProjectReadTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_unavailable_tool_missing_scope_and_compound_or_filtered_requests_are_not_forced(): void
    {
        $query = 'Есть на нашем объекте просроченные задачи? Сколько их?';
        self::assertNull(AssistantRequiredProjectRead::toolChoice($this->plan($query), [['name' => 'get_schedule_snapshot']]));
        foreach (['Сколько просроченных задач назначено Иванову на нашем объекте?', 'Сколько просроченных задач и договоров на нашем объекте?',
            'Покажи расходы и прогноз нашего объекта за сентябрь 2025 года.', 'Покажи расходы нашего объекта.',
            'Какие расходы нашего объекта по договору за сентябрь 2025 года?',
            'Покажи расходы на аренду техники нашего объекта за сентябрь 2025 года.',
            'Покажи расходы нашего объекта за сентябрь 2025 года в долларах.',
            'Покажи расходы нашего объекта за сентябрь 2025 года и сравни с августом.'] as $question) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($question)), $question);
        }
        self::assertNull(AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query, 'context' => ['project_id' => 52]]]));
        $plan = $this->plan($query);
        $plan['request']['context']['entity_refs'][] = ['type' => 'project', 'id' => 53];
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
    }

    private function plan(string $query): array
    {
        return ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }

    public function test_current_object_stock_uses_the_native_project_stock_scope(): void
    {
        $read = AssistantRequiredProjectRead::fromPlan($this->plan('Что сейчас есть на складе текущего объекта и сколько осталось?'));
        self::assertSame('get_project_material_stock', $read['name']);
        self::assertSame(['project_id' => 52, 'offset' => 0, 'limit' => 20], $read['arguments']);
        self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan('Сколько бетона осталось на складе текущего объекта?')));
        $stock = AssistantRequiredProjectRead::bindArguments($this->plan('Что сейчас есть на складе текущего объекта и сколько осталось?'),
            'get_project_material_stock', ['project_id' => 99, 'offset' => 20, 'limit' => 1]);
        self::assertSame(['project_id' => 52, 'offset' => 20, 'limit' => 20], $stock);
        $list = AssistantRequiredProjectRead::bindArguments($this->plan('Какие работы уже приняли на текущем объекте?'),
            'assistant_domain_search', ['project_id' => 99, 'cursor' => 'signed-continuation', 'predicate' => null]);
        self::assertSame('signed-continuation', $list['cursor']);
        self::assertSame(52, $list['project_id']);
        self::assertSame('confirmed', $list['predicate']);
    }

    public function test_current_project_list_reads_bind_entity_and_status_and_keep_compound_filters_unforced(): void
    {
        foreach ([['Какие работы уже приняли на текущем объекте?', 'works', 'completed_work', 'confirmed'],
            ['Что ещё ждём от поставщиков на этом объекте?', 'warehouse', 'project_material_delivery', 'supplier_waiting'],
            ['Какие незакрытые замечания есть по качеству на нашем объекте?', 'quality', 'quality_defect', 'unresolved']] as [$query, $domain, $type, $predicate]) {
            $read = AssistantRequiredProjectRead::fromPlan($this->plan($query));
            self::assertSame('assistant_domain_search', $read['name']);
            self::assertSame($domain, $read['arguments']['domain']);
            self::assertSame($type, $read['arguments']['entity_type']);
            self::assertSame($predicate, $read['arguments']['predicate']);
            self::assertSame(52, $read['arguments']['project_id']);
            self::assertSame('', $read['arguments']['query']);
        }
        foreach (['Какие работы приняли вчера на текущем объекте?', 'Какие незакрытые замечания Иванова есть на нашем объекте?',
            'Что ждём от поставщиков и какие работы приняли на этом объекте?',
            'Какие работы ещё не приняли на текущем объекте?', 'Какие не принятые работы на текущем объекте?',
            'Что уже не ждём от поставщиков на этом объекте?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        }
    }

    public function test_concrete_unit_price_read_binds_material_and_unit_without_requiring_a_project_selector(): void
    {
        foreach (['Найди в любой нашей смете, сколько стоит куб бетона.', 'Почём куб бетона у нас в сметах?', 'Покажи цена за м3 бетона в нашей смете'] as $query) {
            $read = AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query]]);
            self::assertSame('search_estimate_positions', $read['name']);
            self::assertSame('concrete_m3', $read['arguments']['price_subject']);
            self::assertSame('бетон', $read['arguments']['query']);
        }
        foreach (['Сколько стоит куб бетона и тонна арматуры?', 'Сколько стоит куб бетона В25?', 'Сколько стоит куб бетона в смете №17?', 'Сколько стоит куб бетона на текущем объекте?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query]]));
        }
    }
}
