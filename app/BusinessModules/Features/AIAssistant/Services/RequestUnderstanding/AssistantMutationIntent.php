<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

final class AssistantMutationIntent
{
    private const COMMANDS = [
        'create' => ['создай', 'добавь', 'создайте', 'добавьте'],
        'update' => ['измени', 'обнови', 'назначь', 'перенеси', 'увеличь', 'увеличьте', 'уменьши', 'уменьшите',
            'настрой', 'настройте', 'установи', 'установите', 'снизь', 'снизьте', 'повысь', 'повысьте', 'поменяй', 'поменяйте',
            'задай', 'задайте', 'выставь', 'выставьте'],
        'delete' => ['удали', 'удалите'],
        'approve' => ['утверди', 'согласуй', 'одобри', 'подтверди платеж'],
        'send' => ['отправь уведомление', 'уведоми'],
    ];

    public static function type(string $message): ?string
    {
        $normalized = self::normalize($message);
        foreach (self::COMMANDS as $type => $commands) {
            foreach ($commands as $command) {
                if (preg_match('/^(?:пожалуйста |прошу )?'.preg_quote($command, '/').'(?: |$)/u', $normalized) === 1) {
                    return $type;
                }
            }
        }

        return null;
    }

    public static function containsRequest(string $message): bool
    {
        $commands = array_merge(...array_values(self::COMMANDS));
        $commands = array_merge($commands, ['создать', 'добавить', 'измените', 'изменить', 'обновите', 'обновить',
            'увеличить', 'уменьшить', 'настроить', 'установить', 'снизить', 'повысить', 'поменять', 'задать', 'выставить',
            'назначьте', 'назначить', 'перенесите', 'перенести', 'переносим', 'сдвинь', 'сдвиньте', 'сдвинуть',
            'удалить', 'утвердите', 'утвердить', 'согласуйте', 'согласовать', 'одобрите', 'одобрить',
            'подтверди', 'подтвердите', 'подтвердить', 'отправь', 'отправьте', 'отправить', 'уведомите', 'уведомить', 'оплати', 'оплатите', 'оплатить']);
        $normalized = self::normalize($message);
        foreach ($commands as $command) {
            $prefix = str_ends_with($command, 'ть') || str_ends_with($command, 'ти')
                ? '(?:^(?:(?:пожалуйста|прошу|хочу|надо|нужно|давай) )?| (?:и|затем|потом) (?:(?:пожалуйста|прошу) )?)'
                : '(?<![\pL\pN])';
            if (preg_match('/'.$prefix.preg_quote($command, '/').'(?![\pL\pN])/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $message): string
    {
        $normalized = str_replace('ё', 'е', mb_strtolower(trim($message)));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';
    }
}
