<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantModelArgumentsTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_model_period_currency_and_nullable_values_survive_existing_read_defaults(): void
    {
        $plan = $this->plan('Сравни бюджет и расходы нашего проекта за март 2026, с 10 по 20 число.');
        self::assertSame('get_project_finance_overview', AssistantRequiredProjectRead::fromPlan($plan)['name']);
        $model = ['project_id' => 52, 'period_start' => '2026-03-10', 'period_end' => '2026-03-20', 'currency' => 'USD'];

        self::assertSame($model, AssistantRequiredProjectRead::bindArguments($plan, 'get_project_finance_overview', $model));
        $nullable = array_replace($model, ['period_start' => null, 'currency' => null]);
        self::assertSame($nullable, AssistantRequiredProjectRead::bindArguments($plan, 'get_project_finance_overview', $nullable));
        self::assertSame(AssistantRequiredProjectRead::fromPlan($plan)['arguments'],
            AssistantRequiredProjectRead::bindArguments($plan, 'get_project_finance_overview', []));
    }

    public function test_model_filter_focus_and_paging_are_not_reclassified_from_the_question(): void
    {
        foreach ([
            ['Какие договоры нашего объекта имеют просроченную дату окончания?', 'get_project_contract_roster', ['project_id' => 52, 'deadline_filter' => 'all']],
            ['Какая из просроченных задач сильнее всего влияет на общий срок проекта? Покажи почему так и что надо разблокировать.', 'analyze_project_schedule_impact', ['project_id' => 52, 'focus' => 'dependencies']],
            ['Какие счета по нашему объекту ещё не оплачены, сколько по каждому и что мне проверить перед оплатой?', 'get_project_payment_readiness', ['project_id' => 52, 'offset' => 40]],
        ] as [$query, $tool, $model]) {
            $plan = $this->plan($query);
            self::assertSame($tool, AssistantRequiredProjectRead::fromPlan($plan)['name'], $query);
            self::assertSame($model, AssistantRequiredProjectRead::bindArguments($plan, $tool, $model));
        }
    }

    public function test_explicit_invalid_period_reaches_existing_financial_validation_unchanged(): void
    {
        $plan = $this->plan('Сравни бюджет и расходы нашего проекта за март 2026.');
        $arguments = ['project_id' => 52, 'period_start' => '2026-03-20', 'period_end' => '2026-03-10', 'currency' => null];
        $bound = AssistantRequiredProjectRead::bindArguments($plan, 'get_project_finance_overview', $arguments);
        self::assertSame($arguments, $bound);
        $reader = (new ReflectionClass(AssistantLiveProjectFinanceReader::class))->newInstanceWithoutConstructor();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('live_project_finance_arguments_invalid');
        $reader->read(new User, new Organization, $bound);
    }

    private function plan(string $query): array
    {
        return ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }
}
