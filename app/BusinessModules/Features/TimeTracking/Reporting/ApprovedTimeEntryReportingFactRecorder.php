<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\ExactDecimal;
use App\BusinessModules\Features\TimeTracking\Reporting\Models\ApprovedTimeEntryReportingFact;
use App\Models\TimeEntry;
use DomainException;

final readonly class ApprovedTimeEntryReportingFactRecorder
{
    public function record(TimeEntry $entry): ApprovedTimeEntryReportingFact
    {
        if ($entry->status !== 'approved' || $entry->approved_at === null) {
            throw new DomainException('approved_time_entry_reporting_fact_status_invalid');
        }

        $customFields = is_array($entry->custom_fields) ? $entry->custom_fields : [];
        $currency = mb_strtoupper((string) ($customFields['rate_currency'] ?? config('payments.defaults.currency', 'RUB')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('approved_time_entry_reporting_currency_invalid');
        }

        $rateMinor = $entry->hourly_rate === null
            ? null
            : ExactDecimal::minor((string) $entry->hourly_rate);
        $costMinor = $rateMinor === null
            ? null
            : ExactDecimal::multiplyMinor($rateMinor, (string) $entry->hours_worked);
        $payload = [
            'organization_id' => (int) $entry->organization_id,
            'time_entry_id' => (int) $entry->id,
            'project_id' => (int) $entry->project_id,
            'task_id' => $entry->task_id === null ? null : (int) $entry->task_id,
            'work_type_id' => $entry->work_type_id === null ? null : (int) $entry->work_type_id,
            'work_date' => $entry->work_date->format('Y-m-d'),
            'currency' => $currency,
            'currency_source' => isset($customFields['rate_currency']) ? 'time_entry_rate' : 'organization_payment_default',
            'hours' => (string) $entry->hours_worked,
            'hourly_rate_minor' => $rateMinor,
            'cost_minor' => $costMinor,
            'quality_status' => $costMinor === null ? 'partial' : 'complete',
            'approved_at' => $entry->approved_at->format(DATE_ATOM),
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($payload));

        $fact = ApprovedTimeEntryReportingFact::query()->firstOrCreate(
            [
                'organization_id' => $payload['organization_id'],
                'time_entry_id' => $payload['time_entry_id'],
            ],
            [...$payload, 'source_hash' => $sourceHash],
        );
        if (! hash_equals((string) $fact->source_hash, $sourceHash)) {
            throw new DomainException('approved_time_entry_reporting_fact_replay_conflict');
        }

        return $fact;
    }
}
