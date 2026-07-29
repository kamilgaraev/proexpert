<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO;

use InvalidArgumentException;

final readonly class HandoverChecklistFact
{
    public function __construct(
        public string $code,
        public string $status,
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1
            || !in_array($status, ['pending', 'accepted', 'rejected', 'ready_for_reinspection'], true)
        ) {
            throw new InvalidArgumentException('handover_checklist_fact_invalid');
        }
    }
}
