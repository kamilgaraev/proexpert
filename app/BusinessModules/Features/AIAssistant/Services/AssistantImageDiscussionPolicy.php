<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantImageDiscussionPolicy
{
    public static function isDiscussion(string $query, bool $hasCurrentImages, bool $previousImageDiscussion): bool
    {
        $query = mb_strtolower(trim($query));
        if (self::requestsSystemData($query)) {
            return false;
        }

        return $hasCurrentImages || ($previousImageDiscussion && self::isFollowUp($query));
    }

    private static function requestsSystemData(string $query): bool
    {
        if (preg_match('/(?:\bмост\b|\bу нас\b|\bв системе\b|\bиз системы\b|\bв базе\b|\bиз базы\b|баз[а-я]* знаний)/u', $query) === 1) {
            return true;
        }

        return preg_match('/(?:найд[иь]|покаж[иь]|откр[оы]|провер|свер|сравн|сопостав|созда|измени|удали|сформируй|выгрузи|скачай|сколько).*(?:смет|склад|остатк|проект|договор|плат[её]ж|сч[её]т|отч[её]т|запис|документ)/u', $query) === 1;
    }

    private static function isFollowUp(string $query): bool
    {
        if (mb_strlen($query) > 300) {
            return false;
        }

        return preg_match('/^(?:а\s+)?(?:(?:что|как|почему|зачем|чем)\s+(?:это|эти|этот|эта|здесь|тут|там|так|нет)|что\s+(?:значит|означает)|(?:объясни|поясни|расшифруй|распиши|разверни|подробнее|подробней|детальнее)\b|(?:(?:можно|давай|расскажи|ещ[её])\s+){1,2}(?:подробнее|подробней|детальнее)\b)/u', $query) === 1;
    }
}
