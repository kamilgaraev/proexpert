<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use DomainException;

final class EmploymentTerms
{
    public static function sameSlot(array $left, array $right): bool
    {
        return ($left['relation_type'] ?? null) === ($right['relation_type'] ?? null)
            && (($left['relation_type'] ?? null) === 'civil' || ($left['employment_mode'] ?? null) === ($right['employment_mode'] ?? null));
    }

    /** Pure validation also protects calls outside HTTP. Civil engagements never acquire payroll norms. */
    public static function assert(array $terms): void
    {
        if (! in_array($terms['relation_type'] ?? null, ['employment', 'civil'], true)) {
            throw new DomainException(trans_message('workforce.errors.hr_relation_invalid'));
        }
        if (empty($terms['contract_number']) || empty($terms['contract_date']) || empty($terms['start_date'])) {
            throw new DomainException(trans_message('workforce.errors.hr_relation_invalid'));
        }
        if (! empty($terms['end_date']) && $terms['end_date'] < $terms['start_date']) {
            throw new DomainException(trans_message('workforce.errors.hr_relation_invalid'));
        }
        if ($terms['relation_type'] === 'civil') {
            foreach (['employment_mode', 'job_share', 'weekly_norm_hours', 'summed_accounting_period', 'calendar_profile'] as $field) {
                if (isset($terms[$field])) {
                    throw new DomainException(trans_message('workforce.errors.hr_civil_terms_forbidden'));
                }
            }
            return;
        }
        if (! in_array($terms['employment_mode'] ?? null, ['main', 'internal_secondary', 'external_secondary', 'part_time'], true)
            || (float) ($terms['job_share'] ?? 0) <= 0 || (float) $terms['job_share'] > 1
            || (float) ($terms['weekly_norm_hours'] ?? 0) <= 0 || (float) $terms['weekly_norm_hours'] > 40
            || empty($terms['calendar_profile'])
            || ! in_array($terms['timezone'] ?? '', timezone_identifiers_list(), true)
            || ! in_array($terms['summed_accounting_period'] ?? null, [null, 'month', 'quarter', 'year'], true)) {
            throw new DomainException(trans_message('workforce.errors.hr_relation_invalid'));
        }
    }
}
