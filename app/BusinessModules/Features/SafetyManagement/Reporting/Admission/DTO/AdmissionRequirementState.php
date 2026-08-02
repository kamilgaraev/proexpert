<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdmissionRequirementState
{
    public function __construct(
        public string $code,
        public string $type,
        public string $status,
        public bool $mandatory,
        public bool $verified,
        public ?DateTimeImmutable $validUntil,
        public ?string $evidenceType,
        public ?int $evidenceId,
        public ?array $medicalDetails = null,
        public bool $waiverAllowed = false,
        public bool $waiverEvidenceRequired = true,
    ) {
        if (trim($code) === '' || trim($type) === '' || trim($status) === '' || ($evidenceId !== null && $evidenceId < 1)) {
            throw new InvalidArgumentException('admission_requirement_state_invalid');
        }
    }
}
