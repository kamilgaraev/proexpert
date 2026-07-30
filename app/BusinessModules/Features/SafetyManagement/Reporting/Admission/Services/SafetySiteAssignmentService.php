<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetySiteWorkforceAssignment;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SafetySiteAssignmentService
{
    private const MAPPING_SOURCE = 'workforce_employee_assignments';

    public function assign(
        int $organizationId,
        int $projectId,
        int $siteId,
        int $workforceAssignmentId,
        int $employeeId,
        string $validFrom,
        ?string $validTo,
        string $mappingSource,
    ): SafetySiteWorkforceAssignment {
        $from = $this->date($validFrom);
        $to = $validTo === null ? null : $this->date($validTo);
        if (! $from instanceof CarbonImmutable || ($to !== null && $to < $from)) {
            throw new DomainException('REPORT_SOURCE_UNAVAILABLE');
        }
        if ($mappingSource !== self::MAPPING_SOURCE) {
            throw new DomainException('REPORT_SOURCE_UNAVAILABLE');
        }

        return DB::transaction(function () use (
            $organizationId,
            $projectId,
            $siteId,
            $workforceAssignmentId,
            $employeeId,
            $validFrom,
            $validTo,
        ): SafetySiteWorkforceAssignment {
            $siteExists = SafetySite::query()
                ->whereKey($siteId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->exists();
            $assignmentExists = DB::table('workforce_employee_assignments')
                ->where('id', $workforceAssignmentId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('employee_id', $employeeId)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', $validFrom)
                ->where(static function ($query) use ($validTo): void {
                    $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validTo ?? '9999-12-31');
                })
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->exists();
            if (! $siteExists || ! $assignmentExists) {
                throw new DomainException('REPORT_SOURCE_UNAVAILABLE');
            }

            $existing = SafetySiteWorkforceAssignment::query()
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('safety_site_id', $siteId)
                ->where('workforce_assignment_id', $workforceAssignmentId)
                ->whereDate('valid_from', $validFrom)
                ->where(static function ($query) use ($validTo): void {
                    $validTo === null
                        ? $query->whereNull('valid_to')
                        : $query->whereDate('valid_to', $validTo);
                })
                ->first();
            if ($existing instanceof SafetySiteWorkforceAssignment) {
                return $existing;
            }

            $overlap = SafetySiteWorkforceAssignment::query()
                ->where('organization_id', $organizationId)
                ->where('workforce_assignment_id', $workforceAssignmentId)
                ->whereDate('valid_from', '<=', $validTo ?? '9999-12-31')
                ->where(static function ($query) use ($validFrom): void {
                    $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validFrom);
                })
                ->exists();
            if ($overlap) {
                throw new DomainException('REPORT_SOURCE_UNAVAILABLE');
            }

            $payload = [
                'employee_id' => $employeeId,
                'mapping_source' => self::MAPPING_SOURCE,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'safety_site_id' => $siteId,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'workforce_assignment_id' => $workforceAssignmentId,
            ];

            return SafetySiteWorkforceAssignment::query()->create($payload + [
                'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
        });
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (! $date instanceof CarbonImmutable
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
