<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

final class LookaheadReadinessRedactionProjector
{
    private const FIELD_PERMISSIONS = [
        'blocker_description' => 'schedule.readiness.blockers.sensitive.view',
        'blocker_title' => 'schedule.readiness.blockers.details.view',
        'evidence_locator' => 'schedule.readiness.evidence.view',
        'owner_ref' => 'schedule.readiness.blockers.identity.view',
        'vendor_ref' => 'schedule.readiness.blockers.commercial.view',
    ];

    public function project(array $row, array $permissions): array
    {
        $permissionSet = array_fill_keys($permissions, true);
        $redacted = [];

        foreach (self::FIELD_PERMISSIONS as $field => $permission) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            if (! isset($permissionSet[$permission])) {
                $row[$field] = null;
                $redacted[] = $field;
            }
        }

        sort($redacted, SORT_STRING);
        $row['redacted_fields'] = $redacted;

        return $row;
    }

    public function projectForExport(array $row, array $permissions): array
    {
        return $this->project($row, $permissions);
    }
}
