<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadConstraintState
{
    public function __construct(
        public int $constraintId,
        public string $type,
        public string $severity,
        public string $status,
        public ?DateTimeImmutable $waiverUntil,
        public ?string $waiverEvidenceRef,
        public ?DateTimeImmutable $openedAt = null,
        public ?string $linkedResourceType = null,
        public ?int $linkedResourceId = null,
        public array $transitionLineage = [],
    ) {
        if ($constraintId < 1
            || trim($type) === ''
            || trim($severity) === ''
            || trim($status) === ''
            || (($linkedResourceType === null) !== ($linkedResourceId === null))
            || ($linkedResourceId !== null && $linkedResourceId < 1)
            || ! array_is_list($transitionLineage)
        ) {
            throw new InvalidArgumentException('lookahead_constraint_state_invalid');
        }
        foreach ($transitionLineage as $transition) {
            if (! is_array($transition)
                || (int) ($transition['id'] ?? 0) < 1
                || (int) ($transition['version'] ?? 0) < 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($transition['source_hash'] ?? '')) !== 1
            ) {
                throw new InvalidArgumentException('lookahead_constraint_state_invalid');
            }
        }
    }
}
