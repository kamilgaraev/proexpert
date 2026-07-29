<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadReadinessPolicyVersion
{
    public function __construct(
        public int $version,
        public int $organizationId,
        public int $horizonDays,
        public array $eligibleTaskStatuses,
        public array $mandatoryConstraintTypes,
        public array $hardSeverities,
        public bool $waiverEvidenceRequired,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public string $timezone,
        public string $sourceHash,
        public ?int $projectId = null,
        public ?int $policyId = null,
    ) {
        if ($version < 1
            || $organizationId < 1
            || $horizonDays < 1
            || !array_is_list($eligibleTaskStatuses)
            || !array_is_list($mandatoryConstraintTypes)
            || !array_is_list($hardSeverities)
            || $eligibleTaskStatuses === []
            || ($effectiveUntil !== null && $effectiveUntil < $effectiveFrom)
            || trim($timezone) === ''
            || ($policyId !== null && $policyId < 1)
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
        ) {
            throw new InvalidArgumentException('lookahead_policy_version_invalid');
        }
    }

    public function appliesAt(DateTimeImmutable $asOf): bool
    {
        return $asOf >= $this->effectiveFrom
            && ($this->effectiveUntil === null || $asOf <= $this->effectiveUntil);
    }
}
