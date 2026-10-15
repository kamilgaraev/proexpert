<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

final class AssistantMutationIntent
{
    private const ATTRIBUTE_UPDATE = '(?:сделай(?:те)?|сделать|примени(?:те)?|применить|поставь(?:те)?|поставить|выставь(?:те)?|выставить|задай(?:те)?|задать|настрой(?:те)?|настроить|установи(?:те)?|установить|увеличь(?:те)?|увеличить|уменьши(?:те)?|уменьшить|снизь(?:те)?|снизить|повысь(?:те)?|повысить|поменяй(?:те)?|поменять|подправь(?:те)?|подправить|скорректируй(?:те)?|скорректировать|отрегулируй(?:те)?|отрегулировать|подними(?:те)?|поднять|понизь(?:те)?|понизить|сократи(?:те)?|сократить|продли(?:те)?|продлить|сбрось(?:те)?|сбросить|обнули(?:те)?|обнулить)\s+(?:(?:мне|нам|пожалуйста|нов[а-я]*|друг[а-я]*|текущ[а-я]*|максимальн[а-я]*|минимальн[а-я]*|общ[а-я]*|дневн[а-я]*|суточн[а-я]*|месячн[а-я]*|разов[а-я]*|единовременн[а-я]*|установленн[а-я]*|наш[а-я]*|сво[а-я]*)\s+){0,4}(?:лимит|срок|порог|статус|сумм|дат|настройк|доступ|ставк|цен|бюджет|количеств|договор)[а-я]*(?![\pL\pN])';

    private const COMMANDS = [
        'create' => ['создай', 'добавь', 'создайте', 'добавьте'],
        'update' => ['измени', 'обнови', 'назначь', 'перенеси'],
        'delete' => ['удали', 'удалите'],
        'approve' => ['утверди', 'согласуй', 'одобри', 'подтверди платеж'],
        'send' => ['отправь уведомление', 'уведоми'],
    ];

    public static function type(string $message): ?string
    {
        $normalized = self::normalize($message);
        if (preg_match('/^(?:пожалуйста |прошу )?'.self::ATTRIBUTE_UPDATE.'/u', $normalized) === 1) {
            return 'update';
        }
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
            'назначьте', 'назначить', 'перенесите', 'перенести', 'переносим', 'сдвинь', 'сдвиньте', 'сдвинуть',
            'удалить', 'утвердите', 'утвердить', 'согласуйте', 'согласовать', 'одобрите', 'одобрить',
            'подтверди', 'подтвердите', 'подтвердить', 'отправь', 'отправьте', 'отправить', 'уведомите', 'уведомить', 'оплати', 'оплатите', 'оплатить']);
        $normalized = self::normalize($message);
        if (preg_match('/(?<![\pL\pN])'.self::ATTRIBUTE_UPDATE.'/u', $normalized) === 1) {
            return true;
        }
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
