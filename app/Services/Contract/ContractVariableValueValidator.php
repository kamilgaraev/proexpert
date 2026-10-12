<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Closure;

final class ContractVariableValueValidator
{
    public function __construct(private readonly ContractVariableDefinitionValidator $definitions = new ContractVariableDefinitionValidator) {}

    public function validate(array $definition, mixed $value, ?Closure $entityAccessible = null): mixed
    {
        $this->definitions->validate($definition);

        return $this->value($definition, $value, $entityAccessible, 'value');
    }

    private function value(array $definition, mixed $value, ?Closure $entityAccessible, string $path): mixed
    {
        if ($value === null) {
            if ($definition['required'] ?? false) {
                $this->invalid($path, 'Заполните обязательное поле.');
            }

            return null;
        }
        $constraints = $definition['constraints'] ?? [];
        $type = $definition['type'];
        if ($type === 'text') {
            if (!is_string($value) || mb_strlen($value) > ($constraints['max_length'] ?? 100000)
                || (($definition['required'] ?? false) && trim($value) === '')) {
                $this->invalid($path, !is_string($value) ? 'Введите текст. Числовые идентификаторы, например ИНН, храните как текст.' : 'Введите непустой текст длиной не больше '.($constraints['max_length'] ?? 100000).' символов.');
            }
        } elseif (in_array($type, ['number', 'percentage'], true)) {
            $this->number($value, $constraints, $path);
        } elseif ($type === 'money') {
            if (!is_array($value) || array_diff(array_keys($value), ['amount', 'currency']) !== []
                || !is_string($value['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $value['currency']) !== 1
                || (isset($constraints['currency']) && $constraints['currency'] !== $value['currency'])) {
                $this->invalid($path, 'Укажите сумму и валюту'.(isset($constraints['currency']) ? ' '.$constraints['currency'] : ' в формате из трёх букв, например RUB').'.');
            }
            $this->number($value['amount'] ?? null, $constraints, $path.'.amount');
        } elseif ($type === 'date') {
            if (!$this->definitions->date($value)
                || (isset($constraints['min']) && strcmp($value, $constraints['min']) < 0)
                || (isset($constraints['max']) && strcmp($value, $constraints['max']) > 0)) {
                $this->invalid($path, 'Выберите корректную дату'.(isset($constraints['min']) ? ' не раньше '.$constraints['min'] : '').(isset($constraints['max']) ? ' не позже '.$constraints['max'] : '').'.');
            }
        } elseif ($type === 'boolean') {
            if (!is_bool($value)) {
                $this->invalid($path, 'Выберите «Да» или «Нет».');
            }
        } elseif ($type === 'choice') {
            if (!is_string($value) || !in_array($value, array_column($definition['options'], 'id'), true)) {
                $this->invalid($path, 'Выберите один из доступных вариантов. Прежний вариант мог быть удалён из определения поля.');
            }
        } elseif ($type === 'entity') {
            if (!is_array($value) || array_diff(array_keys($value), ['type', 'id']) !== []
                || ($value['type'] ?? null) !== $definition['entity_type'] || !is_int($value['id'] ?? null) || $value['id'] < 1
                || $entityAccessible === null || $entityAccessible($value['type'], $value['id']) !== true) {
                $this->invalid($path, 'Выберите доступную запись из списка вашей организации. У записи могли измениться права доступа.');
            }
        } elseif ($type === 'table') {
            if (!is_array($value) || !array_is_list($value)
                || count($value) < ($constraints['min_rows'] ?? 0) || count($value) > ($constraints['max_rows'] ?? 10000)) {
                $this->invalid($path);
            }
            $ids = [];
            $columns = array_column($definition['columns'], 'definition', 'id');
            foreach ($value as $index => $row) {
                if (!is_array($row) || array_diff(array_keys($row), ['id', 'values']) !== []
                    || !$this->definitions->identifier($row['id'] ?? null) || isset($ids[$row['id']])
                    || !is_array($row['values'] ?? null) || array_diff(array_keys($row['values']), array_keys($columns)) !== []) {
                    $this->invalid($path.'.'.$index);
                }
                $ids[$row['id']] = true;
                foreach ($columns as $id => $column) {
                    $value[$index]['values'][$id] = $this->value($column, $row['values'][$id] ?? null, $entityAccessible, $path.'.'.$index.'.'.$id);
                }
            }
        }

        return $value;
    }

    private function number(mixed $value, array $constraints, string $path): void
    {
        if (!$this->definitions->decimal($value)) {
            $this->invalid($path, 'Введите число с точкой в качестве десятичного разделителя, без пробелов и единиц измерения.');
        }
        $text = (string) $value;
        $fraction = explode('.', $text, 2)[1] ?? '';
        if (strlen($fraction) > ($constraints['scale'] ?? 12)
            || (isset($constraints['min']) && $this->definitions->compareDecimals($text, (string) $constraints['min']) < 0)
            || (isset($constraints['max']) && $this->definitions->compareDecimals($text, (string) $constraints['max']) > 0)) {
            $this->invalid($path, 'Допустимо не больше '.($constraints['scale'] ?? 12).' знаков после точки'.(isset($constraints['min']) ? '; минимум '.$constraints['min'] : '').(isset($constraints['max']) ? '; максимум '.$constraints['max'] : '').'.');
        }
    }

    private function invalid(string $path, string $reason = 'Проверьте структуру таблицы, обязательные ячейки и количество строк.'): never
    {
        preg_match('/^value\.(\d+)/', $path, $row);
        $prefix = isset($row[1]) ? 'Строка '.((int) $row[1] + 1).': ' : '';
        throw new ContractBuilderException('contracts.variable_value_explained', 422, ['reason' => $prefix.$reason]);
    }
}
