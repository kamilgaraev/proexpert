<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantSourceReferenceIdentity
{
    public static function key(array $reference): string
    {
        foreach (['checked_fields', 'required_permissions', 'required_domains'] as $field) {
            if (isset($reference[$field]) && is_array($reference[$field])) {
                $values = [];
                foreach ($reference[$field] as $value) {
                    $encoded = json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    $values[$encoded] = $value;
                }
                ksort($values, SORT_STRING);
                $reference[$field] = array_values($values);
            }
        }

        return hash('sha256', json_encode(self::canonical($reference), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function entityKey(array $reference): string
    {
        return json_encode([
            (string) ($reference['entity_type'] ?? $reference['entityType'] ?? $reference['type'] ?? ''),
            (string) ($reference['entity_id'] ?? $reference['entityId'] ?? $reference['id'] ?? ''),
        ], JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }

        return $value;
    }
}
