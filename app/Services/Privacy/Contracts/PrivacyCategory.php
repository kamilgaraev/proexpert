<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

enum PrivacyCategory: string
{
    case Business = 'business';
    case Personal = 'personal';
    case LocalOnly = 'local_only';
    case FreeText = 'free_text';
    case Unknown = 'unknown';

    public static function forField(string $name): self
    {
        return match ($name) {
            'material', 'quantity', 'unit' => self::Business,
            'person_name', 'contact', 'real_id' => self::Personal,
            'salary', 'passport', 'snils', 'medical', 'payment', 'exact_address', 'owner_secret' => self::LocalOnly,
            'selected_text', 'system_prompt', 'developer_prompt', 'tool_schema', 'schema_example', 'json_key' => self::FreeText,
            default => self::Unknown,
        };
    }
}
