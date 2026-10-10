<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantFactIntentClassifier
{
    private const STATUS = '/(?:статус|состояни|актив|оплачен|утвержд[её]н|согласован|закрыт|одобр[её]н|заверш[её]н|(?:текущ[а-яё]*\s+)?стади[яию])/iu';

    private const DATE = '/(?:когда|(?<![\pL\pN])дат[а-яё]*|срок|создан|измен[её]н)/iu';

    private const OVERDUE = '/(?:просроч[её]н|просрочк)/iu';

    private const OWNER = '/(?:кто\s+(?:[а-яё]+\s+){0,2}ответственн|ответственн[а-яё]*\s+(?:за|по|в|на|у)|кто\s+вед[её]т)/iu';

    private const MONEY = '/(?:сумм|стоимост|(?<![\pL\pN])цен[а-яё]*|ден[еь]г|итог|бюджет|позиц|прям[а-яё]*\s+затрат|накладн|сметн[а-яё]*\s+прибыл|ндс)/iu';

    private const FINANCIAL_RECORD = '/(?:плат[её]ж|оплат|сч[её]т)/iu';

    private const FINANCIAL_EXPLANATION = '/(?<![\pL\pN])(?:почему|зачем|как)(?![\pL\pN])/iu';

    public static function isNarrative(string $query): bool
    {
        if (self::isConversationNumberRequest($query)) {
            return true;
        }
        $financialExplanation = preg_match(self::FINANCIAL_RECORD, $query)
            && preg_match(self::FINANCIAL_EXPLANATION, $query);

        return (bool) $financialExplanation || (bool) preg_match('/(?:как\s+(?:изменить|создать|настроить|добавить|удалить|рассчитать|утвердить|согласовать|закрыть|одобрить|завершить|назначить)|объясни\s+(?:как|правила)|(?:дай|покажи|составь|напиши)\s+инструкци[яю]|^\s*инструкци[яю]\s+(?:по|для|как)|(?:правил[а-яё]*|порядок|процедур[а-яё]*)\s+(?:утверждени|согласовани|закрыти|одобрени|завершени|назначени)|что\s+такое|как\s+(?:формируется|определяется)|(?:покажи|прочитай|выведи|дай)\s+(?:полный\s+)?(?:текст|содержани[ея]|описани[ея])|что\s+написан|цитат|перескажи|выдержк|из\s+текста|содержани[ея]\s+(?:документ|стать|смет|договор)|^\s*(?:назначь|утверди|согласуй|закрой|одобри|заверши))/iu', $query);
    }

    public static function isConversationNumberRequest(string $query): bool
    {
        if (preg_match('/(?:смет|склад|остат|фактич|стоимост|цен[а-яё]*|бюджет|позиц|договор|плат[её]ж|оплат)/iu', $query)) {
            return false;
        }

        return (bool) preg_match('/(?:учебн[а-яё]*\s+(?:арифметическ[а-яё]*\s+)?пример|условн[а-яё]*\s+(?:числ[а-яё]*|объ[её]м[а-яё]*)[^.!?]{0,100}(?:предыдущ[а-яё]*\s+сообщен[а-яё]*|(?:я|мы)\s+(?:назвал[а-яё]*|обсуждал[а-яё]*)))/iu', $query);
    }

    public static function requiresStructuredRead(string $query): bool
    {
        return ! self::isNarrative($query) && (bool) (preg_match(self::STATUS, $query) || preg_match(self::DATE, $query)
            || preg_match(self::OVERDUE, $query) || preg_match(self::OWNER, $query));
    }

    public static function isFactual(string $query): bool
    {
        return ! self::isNarrative($query) && (self::requiresStructuredRead($query) || preg_match(self::MONEY, $query)
            || preg_match(self::FINANCIAL_RECORD, $query) || preg_match('/(?:остат|колич|объ[её]м|сколько)/iu', $query));
    }

    public static function isMoneyOnly(string $query): bool
    {
        return ! self::isNarrative($query) && (bool) preg_match(self::MONEY, $query)
            && ! self::requiresStructuredRead($query) && ! preg_match('/позиц/iu', $query);
    }

    public static function requirements(string $query): array
    {
        $requirements = [];
        $groups = AssistantExtendedDomainRegistry::values('factFieldGroups');
        if (preg_match(self::STATUS, $query)) {
            $requirements[] = array_merge(['status', 'stage_code', 'is_active', 'is_paid', 'paid_at'], $groups['status'] ?? []);
        }
        if (preg_match(self::DATE, $query)) {
            $requirements[] = array_merge(['date', 'start_date', 'end_date', 'estimate_date', 'planned_start_date',
                'planned_end_date', 'planned_finish_date', 'required_date', 'needed_by', 'order_date', 'delivery_date', 'due_date', 'paid_at', 'completion_date',
                'work_date', 'expected_close_at', 'valid_until', 'sent_at', 'published_at', 'planned_issue_date', 'issued_at', 'resolved_at', 'model_date',
                'work_start_date', 'work_end_date', 'rental_start_date', 'rental_end_date', 'equipment_start_at', 'equipment_end_at'], $groups['date'] ?? []);
        }
        if (preg_match(self::OVERDUE, $query)) {
            $requirements[] = ['status', 'due_date', 'end_date', 'planned_end_date', 'planned_finish_date', 'required_date', 'needed_by', 'delivery_date', 'expected_close_at', 'valid_until'];
        }
        if (preg_match(self::OWNER, $query)) {
            $requirements[] = array_merge(['owner_user_id', 'assignee_id', 'assigned_to'], $groups['owner'] ?? []);
        }
        if (preg_match('/позиц/iu', $query)) {
            $requirements[] = ['position_number'];
        }
        if (preg_match('/(?:сумм|стоимост|(?<![\pL\pN])цен[а-яё]*|ден[еь]г|итог|бюджет)/iu', $query)) {
            $requirements[] = array_merge(['unit_price', 'total_amount', 'total_amount_with_vat', 'amount', 'budget_amount', 'planned_advance_amount', 'actual_advance_amount'], $groups['money'] ?? []);
        }
        if (self::requiresUnitPrice($query)) {
            $requirements[] = ['unit_price', 'current_unit_price'];
        }
        if (preg_match('/(?:остат|колич|объ[её]м)/iu', $query)) {
            $requirements[] = array_merge(['quantity', 'quantity_total', 'total_quantity', 'completed_quantity', 'volume_completed', 'material_quantity', 'personnel_count', 'equipment_count'], $groups['quantity'] ?? []);
        }

        return $requirements;
    }

    public static function requiresUnitPrice(string $query): bool
    {
        return (bool) preg_match('/(?:цен[а-яё]*|стоимост[а-яё]*)\s+(?:(?:за|на)\s*)?(?:единиц[а-яё]*|(?:одного|одной|1(?!\d))\s*(?:куб[а-яё]*|(?:квадратн[а-яё]*\s+)?метр[а-яё]*|килограмм[а-яё]*|тонн[а-яё]*|литр[а-яё]*|штук[а-яё]*|м[²³23]?|кг|т|л|шт)(?![\pL\pN]))/iu', $query);
    }
}
