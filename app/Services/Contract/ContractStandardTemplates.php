<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

use function trans_message;

final class ContractStandardTemplates
{
    public const VERSION = 2;
    public const PREFIX = 'most-standard:';
    public const CODES = ['construction', 'subcontract', 'household-repair', 'supply', 'design-survey'];

    public function catalogue(): array
    {
        return array_map(fn (string $code): array => [
            'code' => $code, 'version' => self::VERSION, 'title' => trans_message('contract_templates.'.$code.'.title'),
            'description' => trans_message('contract_templates.'.$code.'.description').' '.trans_message('contract_templates.legal_review_note'), 'contract_profile_code' => 'contract.'.$code,
        ], self::CODES);
    }

    public function fields(string $code): array
    {
        $this->assertCode($code);
        $fields = [];
        foreach (['number' => 'contract.number', 'date' => 'contract.date', 'project' => 'project.name',
            'payer' => 'first_party.name', 'executor' => 'second_party.name'] as $key => $source) {
            $fields[$key] = ['type' => $key === 'date' ? 'date' : 'text', 'required' => $key !== 'project',
                'source' => ['kind' => 'contract_context', 'field' => $source],
                'display' => ['group' => trans_message('contract_templates.groups.context')]];
        }
        foreach (['payer' => 'first_party', 'executor' => 'second_party'] as $prefix => $party) {
            foreach (['inn', 'legal_address', 'email', 'phone', 'bank_name', 'bank_bic', 'bank_account',
                'bank_correspondent_account', 'representative_name', 'representative_position', 'authority_basis',
                'power_of_attorney_number', 'power_of_attorney_date'] as $attribute) {
                $sourceAttribute = match ($attribute) { 'bank_account' => 'settlement_account', 'bank_correspondent_account' => 'correspondent_account', default => $attribute };
                $fields[$prefix.'_'.$attribute] = ['type' => ContractContextSourceFields::type($party.'.'.$sourceAttribute), 'required' => false,
                    'source' => ['kind' => 'contract_context', 'field' => $party.'.'.$sourceAttribute],
                    'display' => ['group' => trans_message('contract_templates.groups.context')]];
            }
        }
        $fields['subject'] = $this->text(true) + ['assignment' => ['target' => 'subject']];
        $fields['location'] = $this->text(true);
        $fields['price'] = ['type' => 'money', 'required' => true, 'constraints' => ['currency' => 'RUB', 'min' => '0'], 'assignment' => ['target' => 'price']];
        $fields['vat_terms'] = ['type' => 'choice', 'required' => true, 'options' => [
            ['id' => 'included', 'label' => 'НДС включён в цену; ставка и сумма указаны в спецификации и расчётных документах'],
            ['id' => 'exempt', 'label' => 'НДС не предъявляется; основание освобождения указано в расчётных документах'],
        ]];
        foreach (['start_date', 'end_date'] as $key) {
            $fields[$key] = ['type' => 'date', 'required' => true, 'assignment' => ['target' => 'schedule', 'field' => $key]];
        }
        $fields['payment_terms'] = $this->text(true) + ['assignment' => ['target' => 'payment_terms']];
        $fields['acceptance'] = $this->text(false);
        $fields['responsibility'] = $this->text(false);
        $fields['guarantee'] = $this->text(true);
        $fields['specification'] = ['type' => 'table', 'required' => true, 'constraints' => ['min_rows' => 1, 'max_rows' => 500],
            'columns' => [
                ['id' => 'name', 'label' => $code === 'supply' ? 'Товар, марка, артикул' : 'Работа и результат', 'definition' => $this->text(true)],
                ['id' => 'unit', 'label' => 'Ед. изм.', 'definition' => ['type' => 'text', 'required' => true, 'constraints' => ['max_length' => 40]]],
                ['id' => 'quantity', 'label' => 'Количество', 'definition' => ['type' => 'number', 'required' => true, 'constraints' => ['min' => '0.000001', 'scale' => 6]]],
                ['id' => 'price', 'label' => 'Цена за единицу', 'definition' => ['type' => 'money', 'required' => true, 'constraints' => ['currency' => 'RUB', 'min' => '0']]],
            ]];
        $fields['stages'] = $this->text(true);
        $fields['materials'] = $this->text(true);
        if (in_array($code, ['construction', 'subcontract', 'design-survey'], true)) $fields['technical_documents'] = $this->text(true);
        if ($code === 'subcontract') $fields['coordination'] = $this->text(true);
        if ($code === 'supply') $fields['delivery_terms'] = $this->text(true) + ['assignment' => ['target' => 'delivery_terms']];
        if ($code === 'design-survey') $fields['rights_terms'] = $this->text(true);
        foreach ($fields as $key => &$definition) {
            $definition['display'] ??= ['group' => trans_message('contract_templates.groups.conditions'),
                'hint' => trans_message('contract_templates.hints.'.$key)];
        }
        unset($definition);

        return $fields;
    }

    public function content(string $code, array $ids): array
    {
        $this->assertCode($code);
        $nodes = [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => $this->inline(trans_message('contract_templates.'.$code.'.title').' № {number}', $ids)],
            $this->paragraph('Дата заключения: {date}. Объект / проект (при наличии): {project}.', $ids),
        ];
        foreach (ContractStandardTemplateText::sections($code) as $section) {
            $body = [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $section['title']]]]];
            foreach ($section['paragraphs'] as $line) $body[] = $this->paragraph($line, $ids);
            $nodes[] = ['type' => 'clause', 'attrs' => ['id' => $section['id']], 'content' => $body];
        }
        $nodes[] = ['type' => 'clause', 'attrs' => ['id' => 'specification'], 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Приложение № 1. Спецификация и смета']]],
            $this->paragraph('Состав, объём и согласованные единичные цены приведены ниже. Общая цена договора: {price}. Налоговые условия: {vat_terms}. Спецификация применяется вместе с предметом и графиком, указанными в договоре; расхождения должны быть устранены письменным соглашением до выполнения соответствующей позиции.', $ids),
            $this->specification($ids['specification'], $code),
            $this->paragraph('Материалы, комплектность, технические характеристики и документы о качестве: {materials}.', $ids),
        ]];
        $nodes[] = ['type' => 'clause', 'attrs' => ['id' => 'schedule_appendix'], 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Приложение № 2. Этапы, сроки и платежи']]],
            $this->paragraph('Начало: {start_date}. Окончание: {end_date}. Этапы, промежуточные результаты и сроки: {stages}.', $ids),
            $this->paragraph('Основания, размеры и сроки платежей, зачёт аванса, удержание (если согласовано): {payment_terms}. Эти условия описывают план оплаты. Каждый фактический платёж и акт оформляется отдельно и подтверждается документом.', $ids),
        ]];
        $requisites = [];
        foreach (['payer' => $code === 'supply' ? 'Покупатель' : ($code === 'subcontract' ? 'Генеральный подрядчик' : 'Заказчик'),
            'executor' => $code === 'supply' ? 'Поставщик' : ($code === 'subcontract' ? 'Субподрядчик' : 'Подрядчик')] as $prefix => $label) {
            $requisites[] = $this->paragraph($label.': {'.$prefix.'}. ИНН: {'.$prefix.'_inn}. Адрес: {'.$prefix.'_legal_address}.', $ids);
            $requisites[] = $this->paragraph('Представитель: {'.$prefix.'_representative_name}; должность: {'.$prefix.'_representative_position}; основание полномочий: {'.$prefix.'_authority_basis}; доверенность № {'.$prefix.'_power_of_attorney_number} от {'.$prefix.'_power_of_attorney_date}.', $ids);
            $requisites[] = $this->paragraph('Банк: {'.$prefix.'_bank_name}; БИК: {'.$prefix.'_bank_bic}; расчётный счёт: {'.$prefix.'_bank_account}; корреспондентский счёт: {'.$prefix.'_bank_correspondent_account}.', $ids);
            $requisites[] = $this->paragraph('Контакты для уведомлений: {'.$prefix.'_email}; {'.$prefix.'_phone}. Подпись: ____________________ / {'.$prefix.'_representative_name}.', $ids);
        }
        $nodes[] = ['type' => 'clause', 'attrs' => ['id' => 'signatures'], 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Реквизиты и подписи сторон']]], ...$requisites,
        ]];

        return ['contract_profile_code' => 'contract.'.$code, 'document' => ['type' => 'doc', 'content' => $nodes],
            'variables' => array_fill_keys(array_values($ids), self::VERSION)];
    }

    private function text(bool $required): array
    {
        return ['type' => 'text', 'required' => $required, 'constraints' => ['max_length' => 10000]];
    }

    private function paragraph(string $value, array $ids): array
    {
        return ['type' => 'paragraph', 'content' => $this->inline($value, $ids)];
    }

    private function inline(string $value, array $ids): array
    {
        $nodes = [];
        foreach (preg_split('/(\{[a-z_]+\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^\{([a-z_]+)\}$/D', $part, $match) === 1) {
                $nodes[] = ['type' => 'variable', 'attrs' => ['variableId' => $ids[$match[1]]]];
            } else $nodes[] = ['type' => 'text', 'text' => $part];
        }

        return $nodes;
    }

    private function specification(string $id, string $code): array
    {
        $columns = ['name' => $code === 'supply' ? 'Товар, марка, артикул' : 'Работа и результат', 'unit' => 'Ед. изм.', 'quantity' => 'Количество', 'price' => 'Цена за единицу'];
        $header = $row = [];
        foreach ($columns as $key => $label) {
            $header[] = ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $label]]]]];
            $row[] = ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $id, 'columnId' => $key]]]]]];
        }

        return ['type' => 'table', 'attrs' => ['columnWidths' => [50, 10, 15, 25]], 'content' => [
            ['type' => 'tableRow', 'attrs' => ['header' => true], 'content' => $header],
            ['type' => 'repeatRows', 'attrs' => ['variableId' => $id], 'content' => [['type' => 'tableRow', 'content' => $row]]],
        ]];
    }

    private function assertCode(string $code): void
    {
        if (!in_array($code, [...self::CODES, 'work'], true)) throw new ContractBuilderException('contracts.library_input_invalid', 422);
    }
}
