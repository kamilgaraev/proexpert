<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services;

use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO\AdmissionRequirementState;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO\WorkforceAdmissionMetric;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceAdmissionFormula
{
    public function evaluate(
        int $assignmentId,
        int $personId,
        int $siteId,
        string $date,
        array $requirements,
    ): WorkforceAdmissionMetric {
        if ($assignmentId < 1 || $personId < 1 || $siteId < 1) {
            throw new InvalidArgumentException('workforce_admission_identity_invalid');
        }
        $snapshotDate = $this->date($date, 'workforce_admission_identity_invalid');

        $blockers = [];
        $warnings = [];
        $seen = [];
        foreach ($requirements as $requirement) {
            $state = $this->normalize($requirement);
            if (isset($seen[$state->code])) {
                throw new InvalidArgumentException('workforce_admission_requirement_duplicate');
            }
            $seen[$state->code] = true;

            $expired = $state->validUntil !== null && $state->validUntil < $snapshotDate;
            $waiverWithoutEvidence = $state->status === 'waived' && $state->evidenceId === null;
            $invalid = in_array($state->status, ['missing', 'expired', 'failed', 'not_fit'], true)
                || $expired
                || ! $state->verified
                || $waiverWithoutEvidence;

            if ($state->mandatory && $invalid) {
                $blockers[] = $state->code;

                continue;
            }

            if ($invalid || $state->status === 'restricted' || $state->status === 'waived') {
                $warnings[] = $state->code;
            }
        }

        sort($blockers, SORT_STRING);
        sort($warnings, SORT_STRING);
        $status = $blockers !== [] ? 'not_admitted' : ($warnings === [] ? 'admitted' : 'partial');

        return new WorkforceAdmissionMetric(
            assignmentId: $assignmentId,
            personId: $personId,
            siteId: $siteId,
            date: $date,
            status: $status,
            blocked: $blockers !== [],
            blockerCodes: $blockers,
            warningCodes: $warnings,
            requirementCount: count($seen),
            admittedPeople: $status === 'admitted' ? 1 : 0,
            partialPeople: $status === 'partial' ? 1 : 0,
            notAdmittedPeople: $status === 'not_admitted' ? 1 : 0,
        );
    }

    public function summarize(iterable $rows): WorkforceAdmissionMetric
    {
        $people = [];
        foreach ($rows as $row) {
            if (! $row instanceof WorkforceAdmissionMetric || $row->status === 'summary') {
                throw new InvalidArgumentException('workforce_admission_summary_row_invalid');
            }

            $key = $row->siteId.':'.$row->personId;
            $current = $people[$key] ?? null;
            if (! $current instanceof WorkforceAdmissionMetric || $this->rank($row->status) > $this->rank($current->status)) {
                $people[$key] = $row;
            }
        }

        $admitted = 0;
        $partial = 0;
        $notAdmitted = 0;
        foreach ($people as $row) {
            $admitted += $row->status === 'admitted' ? 1 : 0;
            $partial += $row->status === 'partial' ? 1 : 0;
            $notAdmitted += $row->status === 'not_admitted' ? 1 : 0;
        }

        return new WorkforceAdmissionMetric(
            assignmentId: 0,
            personId: 0,
            siteId: 0,
            date: 'summary',
            status: 'summary',
            blocked: $notAdmitted > 0,
            blockerCodes: [],
            warningCodes: [],
            requirementCount: 0,
            personDenominator: count($people),
            admittedPeople: $admitted,
            partialPeople: $partial,
            notAdmittedPeople: $notAdmitted,
        );
    }

    private function normalize(mixed $requirement): AdmissionRequirementState
    {
        if ($requirement instanceof AdmissionRequirementState) {
            return $requirement;
        }

        if (! is_array($requirement)) {
            throw new InvalidArgumentException('workforce_admission_requirement_invalid');
        }

        $validUntil = $requirement['valid_until'] ?? null;
        if ($validUntil !== null && ! is_string($validUntil) && ! $validUntil instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('workforce_admission_requirement_invalid');
        }

        return new AdmissionRequirementState(
            code: (string) ($requirement['code'] ?? ''),
            type: (string) ($requirement['type'] ?? $requirement['code'] ?? ''),
            status: (string) ($requirement['status'] ?? 'missing'),
            mandatory: (bool) ($requirement['mandatory'] ?? true),
            verified: (bool) ($requirement['verified'] ?? false),
            validUntil: is_string($validUntil)
                ? $this->date($validUntil, 'workforce_admission_requirement_invalid')
                : $validUntil,
            evidenceType: isset($requirement['evidence_type']) ? (string) $requirement['evidence_type'] : null,
            evidenceId: isset($requirement['evidence_id']) ? (int) $requirement['evidence_id'] : null,
            medicalDetails: is_array($requirement['medical_details'] ?? null) ? $requirement['medical_details'] : null,
        );
    }

    private function rank(string $status): int
    {
        return match ($status) {
            'not_admitted' => 3,
            'partial' => 2,
            'admitted' => 1,
            default => 0,
        };
    }

    private function date(string $value, string $error): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException($error);
        }

        return $date;
    }
}
