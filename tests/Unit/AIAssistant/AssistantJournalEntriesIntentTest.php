<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use PHPUnit\Framework\TestCase;

final class AssistantJournalEntriesIntentTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_specific_journal_content_binds_named_project_and_journal_number_as_a_number(): void
    {
        $query = 'В проекте «Проект тестирования» покажи записи общего журнала №1 «Как хачу так и назову»: даты, содержание и статус согласования каждой записи. Нужны все записи, включая ожидающие согласования.';
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 102]]]],
            'named_project_scope' => ['project_id' => 101, 'message_sha256' => hash('sha256', $query)]];
        self::assertSame('get_project_journal_entries', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null);
        $expected = ['project_id' => 101, 'journal_name' => 'Как хачу так и назову', 'journal_number' => '1', 'offset' => 0];
        self::assertSame(['name' => 'get_project_journal_entries', 'arguments' => $expected], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertSame($expected, AssistantRequiredProjectRead::bindArguments($plan, 'get_project_journal_entries',
            ['project_id' => 102, 'journal_name' => 'Другой', 'journal_number' => '19', 'offset' => 0]));
        unset($plan['named_project_scope']);
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
    }

    public function test_readiness_actions_exports_and_filtered_requests_do_not_become_unfiltered_entries(): void
    {
        foreach (['Согласуй записи журнала №1 в текущем проекте.', 'Выгрузи записи журнала №1 в CSV.',
            'Покажи только утверждённые записи журнала №1.', 'Покажи записи журнала №1 за вчера.',
            'Покажи утверждённые записи журнала №1.', 'Покажи записи журнала №1, ожидающие согласования.',
            'Покажи записи журнала №1 с 1 по 5 октября.', 'Покажи записи журнала №1 и журнала №2.',
            'Покажи записи журнала №1 начиная с 0.', 'Покажи записи журнала №1 начиная с 10001.',
            'Покажи записи журнала №1 по всей организации.', 'Покажи записи журнала №1 и сколько средств осталось.',
            'Покажи записи журнала №1 и акты.', 'Покажи записи журнала №1: даты, содержание и объёмы работ.',
            'Покажи записи журнала №1, которые ещё не подписаны.',
            'Покажи записи журнала №1 и остатки материалов.', 'Покажи записи журнала №1 и платежи по договору.'] as $query) {
            self::assertNotSame('get_project_journal_entries', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null, $query);
        }
        self::assertSame('get_project_journal_readiness', AssistantOperationalReaderRegistry::intent(
            'Что у нас с журналами работ по объекту: есть ли записи, которые ещё не подписаны, и что нужно закрыть перед сдачей?')['name'] ?? null);
    }

    public function test_literal_journal_names_are_not_treated_as_financial_filters_and_pages_are_bound(): void
    {
        $query = 'Покажи все записи журнала №1 «Договор и бюджет» в текущем проекте начиная с 51.';
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 101]]]]];
        self::assertSame($plan, AssistantNamedProjectReadScope::bind($plan, static function (): never {
            self::fail('The page position is not a project name');
        }));
        self::assertSame(['name' => 'get_project_journal_entries', 'arguments' => [
            'project_id' => 101, 'journal_name' => 'Договор и бюджет', 'journal_number' => '1', 'offset' => 50,
        ]], AssistantRequiredProjectRead::fromPlan($plan));
    }

    public function test_journal_of_works_wording_preserves_number_and_literal_title(): void
    {
        foreach (['журнала работ', 'журнала производства работ'] as $noun) {
            foreach ([null, 'Договор и бюджет'] as $name) {
                $journal = 'общего '.$noun.' №1'.($name === null ? '' : ' «'.$name.'»');
                foreach (['В текущем проекте покажи все записи '.$journal.'.', 'Покажи все записи '.$journal.' в текущем проекте.'] as $query) {
                    $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 101]]]]];
                    self::assertSame(['name' => 'get_project_journal_entries', 'arguments' => [
                        'project_id' => 101, 'journal_name' => $name, 'journal_number' => '1', 'offset' => 0,
                    ]], AssistantRequiredProjectRead::fromPlan($plan), $query);
                }
            }
        }
    }
}
