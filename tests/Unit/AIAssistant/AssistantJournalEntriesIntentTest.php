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
            []));
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

    public function test_date_order_and_quantity_constraints_do_not_read_an_unfiltered_first_page(): void
    {
        foreach (['Покажи записи журнала №1 после 5 октября.', 'Покажи последние 10 записей журнала №1.',
            'Покажи десять записей журнала №1.', 'Покажи записи журнала №1 от новых к старым.',
            'Покажи записи журнала №1, №2.', 'Покажи записи журнала №1 начиная с 51 начиная с 101.',
            'Покажи записи журнала №1 по этажам.', 'Покажи записи журнала №1 работников.', 'Покажи записи журнала №1 по обществу.'] as $query) {
            self::assertNotSame('get_project_journal_entries', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null, $query);
        }
    }

    public function test_page_hint_retains_journal_identity_and_explicit_project_for_a_new_request(): void
    {
        $labels = require dirname(__DIR__, 3).'/lang/ru/ai_assistant_journal_entries.php';
        $hint = strtr($labels['more'], [':number' => '1', ':name' => 'Общий журнал', ':project' => 'Проект тестирования', ':next' => '51']);
        self::assertSame(['project_id' => 101, 'journal_name' => 'Общий журнал', 'journal_number' => '1', 'offset' => 50],
            \App\BusinessModules\Features\AIAssistant\Services\AssistantJournalEntriesIntent::arguments(101, $hint));
        self::assertSame('Проект тестирования', AssistantNamedProjectReadScope::name(['request' => ['message' => $hint]]));
    }
}
