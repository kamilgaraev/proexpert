<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;
use PHPUnit\Framework\TestCase;

final class AssistantFactIntentClassifierTest extends TestCase
{
    public function test_natural_price_per_unit_is_not_confused_with_record_count_or_total_contract_price(): void
    {
        foreach (['Найди в любой нашей смете, сколько стоит куб бетона.', 'Почём тонна арматуры?', 'Сколько стоит один квадратный метр штукатурки?'] as $query) {
            self::assertTrue(AssistantFactIntentClassifier::requiresUnitPrice($query), $query);
        }
        foreach (['Сколько стоит весь договор?', 'Сколько кубов бетона осталось?', 'Покажи стоимость работ по объекту.'] as $query) {
            self::assertFalse(AssistantFactIntentClassifier::requiresUnitPrice($query), $query);
        }
    }
    public function test_financial_record_questions_are_factual_but_explanatory_questions_stay_narrative(): void
    {
        $this->assertTrue(AssistantFactIntentClassifier::isFactual('Что с платежами?'));
        $this->assertTrue(AssistantFactIntentClassifier::isFactual('Что со счетами?'));
        $this->assertTrue(AssistantFactIntentClassifier::isFactual('Какой статус платежа?'));

        $this->assertTrue(AssistantFactIntentClassifier::isNarrative('Как проходят платежи?'));
        $this->assertFalse(AssistantFactIntentClassifier::isFactual('Как проходят платежи?'));
        $this->assertTrue(AssistantFactIntentClassifier::isNarrative('Почему платежи задерживаются?'));
        $this->assertFalse(AssistantFactIntentClassifier::isFactual('Почему платежи задерживаются?'));
    }

    public function test_natural_project_expenses_are_financial_but_physical_consumption_is_not(): void
    {
        foreach (['Покажи расходы нашего объекта за сентябрь 2025 года.', 'Сколько потратили на нашем объекте?', 'Затраты проекта за месяц',
            'Покажи расходы нашего объекта за последние 7 дней.', 'Покажи расходы нашего объекта за последний месяц.'] as $query) {
            self::assertTrue(AssistantFactIntentClassifier::isMoneyOnly($query), $query);
            self::assertTrue(AssistantFactIntentClassifier::isFactual($query), $query);
        }
        foreach (['Расход бетона на объекте', 'Сколько воды израсходовали на объекте?', 'Затраты труда на проекте', 'Расход топлива на объекте', 'Сколько часов потратили на объекте?', 'Сколько дней потратили на объекте?', 'Потратили 10 тонн бетона на объекте',
            'Затраты рабочего времени на объекте за сентябрь 2025 года.', 'Потратили 10 часов на объекте'] as $query) {
            self::assertFalse(AssistantFactIntentClassifier::isMoneyOnly($query), $query);
        }
    }

    public function test_required_input_questions_are_clarifications_not_actual_financial_claims(): void
    {
        self::assertTrue(AssistantFactIntentClassifier::isClarificationRequest('Что нужно уточнить для расчёта доставки?'));
        self::assertTrue(AssistantFactIntentClassifier::isNarrative('Какие параметры нужны для расчёта?'));
        self::assertFalse(AssistantFactIntentClassifier::isFactual('Что нужно уточнить для расчёта доставки?'));
        self::assertFalse(AssistantFactIntentClassifier::isClarificationRequest('Покажи текущую стоимость доставки.'));
        self::assertTrue(AssistantFactIntentClassifier::isFactual('Покажи текущую стоимость доставки.'));
        self::assertFalse(AssistantFactIntentClassifier::isClarificationRequest('Что нужно уточнить и какой текущий статус проекта?'));
    }
}
