<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as InputValidator;

final class FinanceInputValidation
{
    public static function make(array $input, array $rules): InputValidator
    {
        $top = [];
        $groups = [];
        foreach ($rules as $path => $rule) {
            if (str_contains($path, '.*')) {
                [$group, $field] = explode('.*', $path, 2);
                $groups[$group][ltrim($field, '.') ?: 'value'] = $rule;
            } else {
                $top[$path] = $rule;
            }
        }
        $validator = Validator::make($input, $top);
        $validator->after(function (InputValidator $validator) use ($input, $groups): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($groups as $group => $rules) {
                $seen = [];
                $distinct = [];
                foreach ($rules as $field => &$rule) {
                    if (in_array('distinct', $rule, true)) {
                        $distinct[$field] = true;
                        $rule = array_values(array_diff($rule, ['distinct']));
                    }
                }
                unset($rule);
                $scalar = array_keys($rules) === ['value'];
                foreach ($input[$group] ?? [] as $index => $row) {
                    $prefix = $group.'.'.$index;
                    if (! $scalar && ! is_array($row)) {
                        $validator->errors()->add($prefix, __('validation.array', ['attribute' => $prefix]));
                        return;
                    }
                    $values = $scalar ? ['value' => $row] : $row;
                    $check = Validator::make($values, $rules);
                    if ($check->fails()) {
                        foreach ($check->errors()->messages() as $field => $messages) {
                            $path = $scalar ? $prefix : $prefix.'.'.$field;
                            foreach ($messages as $message) {
                                $validator->errors()->add($path, $message);
                            }
                        }
                        return;
                    }
                    foreach ($distinct as $field => $_) {
                        $value = $values[$field];
                        $key = in_array('integer', $rules[$field], true) ? (string) (int) $value : (string) $value;
                        if (in_array('uuid', $rules[$field], true)) {
                            $key = strtolower($key);
                        }
                        if (isset($seen[$field][$key])) {
                            $path = $scalar ? $prefix : $prefix.'.'.$field;
                            $validator->errors()->add($path, __('validation.distinct', ['attribute' => $path]));
                            return;
                        }
                        $seen[$field][$key] = true;
                    }
                }
            }
        });

        return $validator;
    }

    public static function sanitize(array $data, array $rules): array
    {
        $fields = [];
        foreach ($rules as $path => $rule) {
            if (in_array('uuid', $rule, true)) {
                if (str_contains($path, '.*.')) {
                    [$group, $field] = explode('.*.', $path, 2);
                    foreach ($data[$group] ?? [] as $index => $row) {
                        $data[$group][$index][$field] = strtolower($row[$field]);
                    }
                } elseif (str_ends_with($path, '.*')) {
                    $group = substr($path, 0, -2);
                    if (isset($data[$group])) {
                        $data[$group] = array_map('strtolower', $data[$group]);
                    }
                } elseif (isset($data[$path])) {
                    $data[$path] = strtolower($data[$path]);
                }
            }
            if (str_contains($path, '.*.')) {
                [$group, $field] = explode('.*.', $path, 2);
                $fields[$group][$field] = true;
            }
        }
        foreach ($fields as $group => $allowed) {
            if (isset($data[$group])) {
                $data[$group] = array_map(fn (array $row): array => array_intersect_key($row, $allowed), $data[$group]);
            }
        }

        return $data;
    }

    public static function validate(array $input, array $rules): array
    {
        return self::sanitize(self::make($input, $rules)->validate(), $rules);
    }
}
