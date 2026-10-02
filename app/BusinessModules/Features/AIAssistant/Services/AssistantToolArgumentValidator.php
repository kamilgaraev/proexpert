<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use RuntimeException;

final class AssistantToolArgumentValidator
{
    public function validate(array $arguments, array $schema): void
    {
        $this->value($arguments, $schema, 0);
    }

    private function value(mixed $value, array $schema, int $depth): void
    {
        if ($depth > 12) {
            $this->invalid();
        }
        foreach (['oneOf', 'anyOf'] as $union) {
            if (is_array($schema[$union] ?? null)) {
                $matches = 0;
                foreach ($schema[$union] as $candidate) {
                    try {
                        if (is_array($candidate)) {
                            $this->value($value, $candidate, $depth + 1);
                            $matches++;
                        }
                    } catch (RuntimeException) {
                    }
                }
                if ($matches === 0 || ($union === 'oneOf' && $matches !== 1)) {
                    $this->invalid();
                }
                return;
            }
        }
        $type = $schema['type'] ?? 'object';
        $types = is_array($type) ? $type : [$type];
        $valid = false;
        foreach ($types as $candidate) {
            $valid = $valid || match ($candidate) {
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
        }
        if (!$valid || (isset($schema['enum']) && !in_array($value, $schema['enum'], true))) {
            $this->invalid();
        }
        if ($value === null) {
            return;
        }
        if (is_string($value)) {
            if (mb_strlen($value) > (int) ($schema['maxLength'] ?? 4000) || mb_strlen($value) < (int) ($schema['minLength'] ?? 0)) {
                $this->invalid();
            }
        }
        if (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum'])) {
                $this->invalid();
            }
        }
        if (!is_array($value)) {
            return;
        }
        if (in_array('object', $types, true)) {
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $required) {
                if (!array_key_exists($required, $value)) {
                    $this->invalid();
                }
            }
            foreach ($value as $key => $item) {
                if (!is_string($key) || !is_array($properties[$key] ?? null)) {
                    $this->invalid();
                }
                $this->value($item, $properties[$key], $depth + 1);
            }
            return;
        }
        if (count($value) > (int) ($schema['maxItems'] ?? 100) || count($value) < (int) ($schema['minItems'] ?? 0)) {
            $this->invalid();
        }
        foreach ($value as $item) {
            if (!is_array($schema['items'] ?? null)) {
                $this->invalid();
            }
            $this->value($item, $schema['items'], $depth + 1);
        }
    }

    private function invalid(): never
    {
        throw new RuntimeException(trans_message('ai_assistant.action_arguments_invalid'));
    }
}
