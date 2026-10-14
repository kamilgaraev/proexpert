<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use Carbon\CarbonImmutable;
use DomainException;

final class HrDocumentRetentionPolicy
{
    /** Unknown classifications stay on hold; the registry never guesses a disposal date. */
    public function classify(array $document, ?string $caseClosedOn, ?array $configuredRules = null): array
    {
        $rules = $configuredRules ?? (array) config('workforce_hr.document_retention_rules', []);
        $rule = $rules[$document['document_type']] ?? null;
        if (! is_array($rule)) {
            if ($document['retention_class'] !== 'unclassified_hold' || ! empty($document['retain_until'])) {
                throw new DomainException(trans_message('workforce.errors.hr_retention_invalid'));
            }
            return ['classification' => 'unclassified_hold', 'disposal_allowed' => false, 'requires_archival_classification' => true];
        }
        $date = CarbonImmutable::parse($document['document_date']);
        $basis = $rule['basis'] ?? 'document_year';
        if (in_array($document['document_type'], ['employment_contract', 'employment_amendment', 'employment_termination_agreement', 'employment_order', 'dismissal_order', 'personnel_card'], true)
            && ($basis !== 'case_closed' || (int) ($rule['minimum_years'] ?? 0) < 50 || (int) ($rule['before_2003_years'] ?? 0) < 75)) {
            throw new DomainException(trans_message('workforce.errors.hr_retention_invalid'));
        }
        $closure = $basis === 'case_closed' ? $caseClosedOn : $document['document_date'];
        $closureYear = $closure !== null ? CarbonImmutable::parse($closure)->year : null;
        $years = (int) ($closureYear !== null && $closureYear < 2003 ? ($rule['before_2003_years'] ?? $rule['minimum_years'] ?? 0) : ($rule['minimum_years'] ?? 0));
        if ($years < 1 || $years > 100 || empty($rule['class']) || empty($rule['legal_reference'])
            || ! in_array($basis, ['document_year', 'case_closed'], true) || $document['retention_class'] !== $rule['class']) {
            throw new DomainException(trans_message('workforce.errors.hr_retention_invalid'));
        }
        $earliest = $closure !== null
            ? CarbonImmutable::parse($closure)->max($date)->addYear()->startOfYear()->addYears($years)->toDateString()
            : null;
        if (! empty($document['retain_until']) && ($earliest === null || $document['retain_until'] < $earliest)) {
            throw new DomainException(trans_message('workforce.errors.hr_retention_invalid'));
        }
        return ['classification' => $rule['class'], 'minimum_years' => $years, 'retention_basis' => $basis,
            'case_closed_on' => $closure, 'period_determined_from' => 'completion_of_recordkeeping',
            'earliest_review_date' => $earliest, 'legal_reference' => $rule['legal_reference'],
            'disposal_allowed' => false, 'requires_archival_appraisal' => true];
    }
}
