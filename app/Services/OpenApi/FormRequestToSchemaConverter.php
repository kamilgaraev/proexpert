<?php

namespace App\Services\OpenApi;

use Illuminate\Foundation\Http\FormRequest;

class FormRequestToSchemaConverter
{
    /**
     * Преобразовать правила в приближенную JSON-схему уровня properties/required.
     */
    public function convert(FormRequest $request): array
    {
        $rules = method_exists($request, 'rules') ? $request->rules() : [];
        $schema = [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];

        foreach ($rules as $field => $definition) {
            $parsed = $this->parseRuleDefinition($definition);
            $schema['properties'][$field] = ['type' => $parsed['type']];
            if ($parsed['required']) {
                $schema['required'][] = $field;
            }
        }

        // Удаляем empty required чтобы не шумело при сравнении
        if (!$schema['required']) {
            unset($schema['required']);
        }

        return $schema;
    }

    private function parseRuleDefinition(string|array $definition): array
    {
        $parts = is_array($definition)
            ? $definition
            : explode('|', (string)$definition);

        $parts = array_map('trim', $parts);

        $type = $this->detectType($parts);
        $required = !in_array('nullable', $parts, true) && !in_array('sometimes', $parts, true);

        return [
            'type'     => $type,
            'required' => $required,
        ];
    }

    private function detectType(array $parts): string
    {
        $map = [
            'integer' => 'integer',
            'numeric' => 'number',
            'boolean' => 'boolean',
            'array'   => 'array',
            'date'    => 'string',
            'email'   => 'string',
            'uuid'    => 'string',
            'string'  => 'string',
        ];

        foreach ($parts as $rule) {
            $ruleName = strtolower(strtok($rule, ':'));
            if (isset($map[$ruleName])) {
                return $map[$ruleName];
            }
        }

        // По умолчанию string
        return 'string';
    }
} 