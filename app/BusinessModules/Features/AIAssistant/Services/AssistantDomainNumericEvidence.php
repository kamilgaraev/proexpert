<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use Illuminate\Database\Eloquent\Model;

final class AssistantDomainNumericEvidence
{
    private const LABELS = [
        'position_number' => 'Номер позиции', 'quantity' => 'Количество', 'quantity_total' => 'Общее количество',
        'total_quantity' => 'Общее количество', 'unit_price' => 'Цена за единицу', 'total_amount' => 'Сумма',
        'total_amount_with_vat' => 'Сумма с НДС', 'amount' => 'Сумма', 'hours' => 'Часы',
        'budget_amount' => 'Бюджет', 'planned_advance_amount' => 'Плановый аванс', 'actual_advance_amount' => 'Фактический аванс', 'completed_quantity' => 'Выполненное количество',
        'hours_worked' => 'Отработанные часы', 'volume_completed' => 'Выполненный объём', 'progress_percent' => 'Прогресс, %',
    ];

    public static function row(Model $model, string $entityType, array $fields, array $reference): ?array
    {
        $numbers = [];
        foreach (array_intersect($fields, array_merge(array_keys(self::LABELS), AssistantExtendedDomainRegistry::values('numericFields'))) as $field) {
            $raw = $model->getRawOriginal($field);
            if (! is_string($raw) && ! is_int($raw)) {
                continue;
            }
            if ($field === 'position_number') {
                if (preg_match('/^\d+(?:\.\d+)*$/D', (string) $raw)) {
                    $numbers[$field] = (string) $raw;
                }
                continue;
            }
            if (! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $raw)) {
                continue;
            }
            $cast = $model->getCasts()[$field] ?? '';
            $scale = preg_match('/^decimal:(\d+)$/D', (string) $cast, $match)
                ? (int) $match[1]
                : (str_contains((string) $raw, '.') ? strlen(explode('.', (string) $raw, 2)[1]) : 0);
            $numbers[$field] = FinanceDecimal::value($raw, $scale);
        }
        if ($numbers === []) {
            return null;
        }
        $parents = array_intersect_key($model->getAttributes(), array_flip(array_intersect($fields, ['project_id', 'contract_id', 'estimate_id', 'estimate_section_id', 'estimate_item_id', 'work_order_id', 'schedule_id'])));
        $row = ['entity_type' => $entityType, 'entity_id' => $model->getKey(), 'fields' => $numbers,
            'parent_ids' => $parents, 'source_ref' => $reference, 'source_version' => $reference['source_version'] ?? $model->getRawOriginal('updated_at')];
        $row['version'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        return $row;
    }

    public static function payload(array $rows, string $fetchedAt): array
    {
        if ($rows === []) {
            return [];
        }
        $lines = [];
        foreach ($rows as $row) {
            $type = match ($row['entity_type']) {
                'estimate' => 'Смета', 'estimate_item' => 'Позиция сметы', 'estimate_item_resource' => 'Ресурс позиции',
                'contract' => 'Договор', 'performance_act' => 'Акт', 'payment_document' => 'Платёжный документ',
                'completed_work' => 'Выполненная работа', default => 'Запись',
            };
            $type = AssistantExtendedDomainRegistry::values('entityLabels')[$row['entity_type']] ?? $type;
            $lines[] = $type.' №'.(string) $row['entity_id'].':';
            foreach ($row['fields'] as $field => $value) {
                $lines[] = (self::LABELS[$field] ?? AssistantExtendedDomainRegistry::values('fieldLabels')[$field] ?? 'Значение').': '.$value;
            }
            $url = $row['source_ref']['navigation']['url'] ?? null;
            if (is_string($url) && preg_match('#^/(?!/)[^\s\[\]()]+$#D', $url)) {
                $lines[] = '[Открыть запись]('.$url.')';
            }
        }
        $evidence = ['rows' => $rows, 'source_refs' => array_column($rows, 'source_ref'), 'fetched_at' => $fetchedAt,
            'version' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'validation_status' => 'partial',
            'scope' => 'returned_entity_fields'];
        return ['financial_evidence' => $evidence, 'server_formatted_answer' => implode("\n", $lines), 'validation_status' => 'partial'];
    }
}
