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
