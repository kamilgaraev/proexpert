<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ContractSettlementOwnerTimestamp
{
    public const MODEL_FORMAT = 'Y-m-d H:i:s.uP';

    private const CANONICAL_FORMAT = 'Y-m-d\TH:i:s.uP';

    private const DATABASE_FORMAT = 'Y-m-d H:i:s.uP';

    public static function canonical(DateTimeInterface $value): string
    {
        return self::utc($value)->format(self::CANONICAL_FORMAT);
    }

    public static function database(DateTimeInterface $value): string
    {
        return self::utc($value)->format(self::DATABASE_FORMAT);
    }

    private static function utc(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
    }
}
