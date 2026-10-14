<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

final class AssistantMutationIntent
{
    public static function type(string $message): ?string
    {
        $normalized = str_replace('ё', 'е', mb_strtolower(trim($message)));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';
        foreach ([
            'create' => ['создай', 'добавь', 'создайте', 'добавьте'],
            'update' => ['измени', 'обнови', 'назначь', 'перенеси'],
            'delete' => ['удали', 'удалите'],
        ] as $type => $commands) {
            foreach ($commands as $command) {
                if (preg_match('/^(?:пожалуйста |прошу )?'.preg_quote($command, '/').'(?: |$)/u', $normalized) === 1) {
                    return $type;
                }
            }
        }

        return null;
    }
}
