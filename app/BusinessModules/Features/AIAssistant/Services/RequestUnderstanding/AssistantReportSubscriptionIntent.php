<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

final class AssistantReportSubscriptionIntent
{
    public static function type(string $message): ?string
    {
        $query = str_replace('ё', 'е', mb_strtolower(trim($message)));
        if (preg_match('/отчет|выгрузк|excel|xlsx|csv|pdf/u', $query) !== 1
            || preg_match('/^(?:как|почему|какие|можно\s+ли|умеешь\s+ли|хочу\s+(?:узнать|понять))(?!\pL)/u', $query) === 1) {
            return null;
        }
        $subscription = preg_match('/подписк|рассылк|подпиши/u', $query) === 1;
        $recurrence = preg_match('/регулярн|автоматическ|ежеднев|еженедел|ежемесяч|кажд[а-я]*\s+(?:день|недел|месяц|понедельник|вторник|сред|четверг|пятниц|суббот|воскресень)/u', $query) === 1;
        $delivery = preg_match('/получать|присылать|присылай|отправлять|доставлять/u', $query) === 1;
        if (! $subscription && ! ($recurrence && $delivery)) {
            return null;
        }
        $prefix = '^(?:(?:пожалуйста|прошу|можешь(?:\s+ли)?|можете(?:\s+ли)?)\s+)*';
        if ($subscription && preg_match('/'.$prefix.'(?:приостанови|приостановить|останови|отключи|выключи|выключить|возобнови|возобновить|включи|измени|изменить|обнови|обновить|перенеси|поставь)(?!\pL)/u', $query) === 1) {
            return 'update';
        }
        if (preg_match('/'.$prefix.'(?:создай|создать|добавь|добавить|сделай|настрой|настроить|подпиши|подписать)(?!\pL)/u', $query) === 1) {
            return 'create';
        }
        if ($recurrence && preg_match('/'.$prefix.'(?:хочу|нужно|надо)(?!\pL)/u', $query) === 1
            && $delivery && preg_match('/(?<!\pL)не\s+(?:получать|присылать|отправлять|доставлять)(?!\pL)/u', $query) !== 1) {
            return 'create';
        }

        return null;
    }
}
