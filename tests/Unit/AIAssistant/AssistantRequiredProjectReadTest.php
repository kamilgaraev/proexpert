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
}
