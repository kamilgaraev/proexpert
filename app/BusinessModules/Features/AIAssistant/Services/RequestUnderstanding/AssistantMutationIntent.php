<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

final class AssistantMutationIntent
{
    private const TOGGLE_COMMANDS = '(?:включи(?:те)?|включить|отключи(?:те)?|отключить)';

    private const TOGGLE_UPDATE = self::TOGGLE_COMMANDS.'\s+'.self::ATTRIBUTE_MODIFIERS.'(?:уведомл|напомин|обязательн|требован)[а-я]*(?!\pL)';

    private const PRESENTATION_COMMANDS = 'сделай(?:те)?|сделать|поставь(?:те)?|поставить|выставь(?:те)?|выставить';

    private const PRESENTATION_REQUEST = '(?<!\pL)(?:'.self::PRESENTATION_COMMANDS.')\s+(?:(?!\s+(?:и|затем|потом)\s+(?:сделай(?:те)?|сделать|'.self::UPDATE_COMMANDS.'|'.self::TOGGLE_COMMANDS.'|измени|удали|создай|обнови|назначь|перенеси|(?:покажи|назови|перечисли|выведи|отобрази|представь|расскажи|объясни|сравни|подсчитай|посчитай|найди)(?:те)?))[^.!?;,])*?(?:таблиц(?!е(?!\pL))[а-я]*|списк(?!е(?!\pL))[а-я]*|перечн(?!е(?!\pL))[а-я]*|подборк(?!е(?!\pL))[а-я]*|сводк(?!е(?!\pL))[а-я]*|обзор(?!е(?!\pL))[а-я]*|жирн[а-я]*\s+шрифт[а-я]*|курсив[а-я]*|колонк(?!е(?!\pL))[а-я]*)(?!\pL)';
    private const ATTRIBUTE_MODIFIERS = '(?:(?:мне|нам|вам|тебе|им|ему|ей|их|его|ее|них|него|нее|этот|эта|это|эти|эту|этого|этому|этим|этой|этом|этих|этими|мо[йяеию][а-я]*|тво[йяеию][а-я]*|ваш[а-я]*|все|всех|всем|кажд[а-я]*|пожалуйста|нов[а-я]*|друг[а-я]*|текущ[а-я]*|максимальн[а-я]*|минимальн[а-я]*|общ[а-я]*|дневн[а-я]*|суточн[а-я]*|месячн[а-я]*|разов[а-я]*|единовременн[а-я]*|установленн[а-я]*|наш[а-я]*|сво[а-я]*|(?:для|по|на|в|к|с|со|у|от|из|об|о|обо|за|перед|под|над|между|без)\s+\pL+(?:\s+\pL+)?)\s+){0,6}';

    private const UPDATE_COMMANDS = 'замени(?:те)?|заменить|примени(?:те)?|применить|поставь(?:те)?|поставить|выставь(?:те)?|выставить|задай(?:те)?|задать|настрой(?:те)?|настроить|установи(?:те)?|установить|увеличь(?:те)?|увеличить|уменьши(?:те)?|уменьшить|снизь(?:те)?|снизить|повысь(?:те)?|повысить|поменяй(?:те)?|поменять|подправь(?:те)?|подправить|скорректируй(?:те)?|скорректировать|отрегулируй(?:те)?|отрегулировать|подними(?:те)?|поднять|понизь(?:те)?|понизить|сократи(?:те)?|сократить|продли(?:те)?|продлить|сбрось(?:те)?|сбросить|обнули(?:те)?|обнулить';

    private const ATTRIBUTE_UPDATE = '(?:(?:сделай(?:те)?|сделать)(?!\s+'.self::ATTRIBUTE_MODIFIERS.'сумм)|'.self::UPDATE_COMMANDS.')\s+'.self::ATTRIBUTE_MODIFIERS.'(?:лимит|срок|порог|статус|сумм|дат|настройк|доступ|ставк|цен|бюджет|количеств|договор|подрядчик|контрагент)[а-я]*(?![\pL\pN])';

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
        if (preg_match('/^(?:пожалуйста |прошу )?(?:'.self::ATTRIBUTE_UPDATE.'|'.self::TOGGLE_UPDATE.')/u', $normalized) === 1) {
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
        if (preg_match('/(?<![\pL\pN])(?:'.self::ATTRIBUTE_UPDATE.'|'.self::TOGGLE_UPDATE.')/u', $normalized) === 1) {
            return true;
        }
        if (preg_match('/(?<!\pL)(?:уведомл|напомин|обязательн|требован|лимит|срок|порог|статус|сумм|дат|настройк|доступ|ставк|цен|бюджет|количеств|договор|подрядчик|контрагент)[а-я]*(?!\pL)[\s\S]*(?<!\pL)(?:'.self::UPDATE_COMMANDS.'|'.self::TOGGLE_COMMANDS.')\s+(?:его|ее|их|это)(?!\pL)/u', $normalized) === 1) {
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

    public static function compoundType(string $message): ?string
    {
        $parts = preg_split('/([.!?;,:\r\n]|\s+[-—–]\s+|\s+(?:и|затем|потом)\s+)/u', mb_strtolower($message), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($index = 2; $index < count($parts); $index += 2) {
            $part = $parts[$index];
            $previousIndex = $index - 2;
            while ($previousIndex > 0 && trim($parts[$previousIndex]) === '') {
                $previousIndex -= 2;
            }
            if (preg_match('/^\s*(?:и|затем|потом)\s*$/u', $parts[$index - 1]) !== 1
                && preg_match('/инструкц|пример|цитат|(?:что\s+)?(?:означает|значит)\s+команд|^\s*вопрос\s*$/u', $parts[$previousIndex]) === 1) {
                continue;
            }
            if (preg_match('/^(?:пожалуйста )?\pL+(?:ть|ти)(?!\pL)/u', self::normalize($part)) === 1) {
                continue;
            }
            $type = self::type($part);
            if ($type !== null) {
                return $type;
            }
            if (self::containsRequest($message)
                && preg_match('/^(?:пожалуйста |прошу )?(?:'.self::UPDATE_COMMANDS.'|'.self::TOGGLE_COMMANDS.')\s+(?:его|ее|их|это)(?!\pL)/u', self::normalize($part)) === 1) {
                return 'update';
            }
        }

        return null;
    }

    public static function containsFollowUpCommand(string $message): bool
    {
        $query = str_replace('ё', 'е', mb_strtolower($message));

        return preg_match('/(?:\s+(?:и|затем|потом)\s+|\s+[-—–]\s+|[.!?;,:\r\n]\s*)(?:пожалуйста[,\s]+)?(?:сделай(?:те)?|сделать|'.self::UPDATE_COMMANDS.'|'.self::TOGGLE_COMMANDS.')(?!\pL)/u', $query) === 1;
    }

    public static function hasPresentationRequest(string $message): bool
    {
        return preg_match('/'.self::PRESENTATION_REQUEST.'/u', str_replace('ё', 'е', mb_strtolower($message))) === 1;
    }

    private static function normalize(string $message): string
    {
        $normalized = str_replace('ё', 'е', mb_strtolower(trim($message)));
        $normalized = preg_replace_callback('/'.self::PRESENTATION_REQUEST.'/u', static fn (array $match): string => preg_replace('/^(?:'.self::PRESENTATION_COMMANDS.')/u', 'покажи', $match[0]) ?? $match[0], $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';
    }
}
