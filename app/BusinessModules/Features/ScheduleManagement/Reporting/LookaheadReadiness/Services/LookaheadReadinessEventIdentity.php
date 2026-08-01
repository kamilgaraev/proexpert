<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

final class LookaheadReadinessEventIdentity
{
    public static function fromIdempotency(int $organizationId, string $idempotencyKey): string
    {
        $hex = substr(hash('sha256', "lookahead-readiness:{$organizationId}:{$idempotencyKey}"), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }
}
