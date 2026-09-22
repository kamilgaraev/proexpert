<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Support;

use DateTimeImmutable;

final class ExecutiveDocumentProfileValidator
{
    public function missingRequiredFields(array $profile, array $data): array
    {
        $missing = [];

        foreach ($profile['fields'] ?? [] as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }

            $value = $data[$field['key']] ?? null;
            if ($this->isEmpty($value)) {
                $missing[$field['key']] = (string) ($field['label'] ?? $field['key']);
            }
        }

        return $missing;
    }

    public function validate(array $profile, array $data): array
    {
        $errors = [];
        $known = [];

        foreach ($profile['fields'] ?? [] as $field) {
            $key = (string) ($field['key'] ?? '');
            $known[$key] = true;
            $value = $data[$key] ?? null;
            if ($this->isEmpty($value)) {
                if (($field['required'] ?? false) === true) {
                    $errors[$key] = (string) ($field['label'] ?? $key);
                }
                continue;
            }
            if (!$this->matchesType($value, (string) ($field['type'] ?? 'text'), $field['options'] ?? null)) {
                $errors[$key] = (string) ($field['label'] ?? $key);
            }
        }

        foreach (array_keys($data) as $key) {
            if (!isset($known[$key])) {
                $errors[(string) $key] = (string) $key;
            }
        }

        return $errors;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    private function matchesType(mixed $value, string $type, mixed $options): bool
    {
        return match ($type) {
            'date' => is_string($value) && $this->isDate($value),
            'select' => is_scalar($value) && is_array($options) && in_array($value, $options, true),
            'multiselect', 'table' => is_array($value),
            'boolean' => is_bool($value),
            default => is_string($value) || is_int($value) || is_float($value),
        };
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
