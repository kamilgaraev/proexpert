<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\Timekeeping;

use Illuminate\Foundation\Http\FormRequest;

final class TimekeepingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $method = $this->route()?->getActionMethod();
        $revision = ['expected_revision' => ['required', 'integer', 'min:1']];
        $reviewRules = ['review' => ['nullable', 'array'], 'review.annual_minutes_before_month' => ['nullable', 'integer', 'min:0', 'max:525600'], 'review.accounting_period_overtime_minutes' => ['nullable', 'numeric', 'min:0', 'max:525600'], 'review.overtime_minutes' => ['nullable', 'numeric', 'min:0', 'max:1440']];
        foreach (['annual_baseline_document_id', 'overtime_consent_document_id', 'overtime_order_document_id', 'weekend_basis_document_id', 'collective_agreement_document_id', 'accounting_period_review_document_id', 'business_trip_work_document_id'] as $field) {
            $reviewRules['review.'.$field] = ['nullable', 'integer', 'min:1'];
        }

        return match ($method) {
            'index' => ['month' => ['nullable', 'date_format:Y-m'], 'project_id' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'], 'page' => ['nullable', 'integer', 'min:1']],
            'store' => ['month' => ['required', 'date_format:Y-m'], 'project_id' => ['nullable', 'integer', 'min:1']],
            'refresh' => $revision,
            'action' => $revision + ['action' => ['required', 'in:submit,verify,approve,close,reopen'], 'comment' => ['nullable', 'string', 'max:2000'], 'basis_reference' => ['nullable', 'string', 'max:255'], 'signed_file_id' => ['nullable', 'integer', 'min:1'], 'document_id' => ['nullable', 'integer', 'min:1'], 'document_revision' => ['nullable', 'integer', 'min:1'], 'signer_name' => ['nullable', 'string', 'max:255'], 'signed_at' => ['nullable', 'date'], 'signed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'paper_original_location' => ['nullable', 'string', 'max:500']],
            'cell' => $revision + $reviewRules + ['employee_id' => ['required', 'integer', 'min:1'], 'employment_contract_id' => ['nullable', 'integer', 'min:1'], 'work_date' => ['required', 'date_format:Y-m-d'], 'reason_code' => ['required', 'string', 'max:80'], 'basis_reference' => ['required', 'string', 'max:255'], 'basis_document_id' => ['nullable', 'integer', 'min:1'], 'comment' => ['nullable', 'string', 'max:2000'], 'intervals' => ['present', 'array', 'max:10'], 'intervals.*.start_at' => ['required', 'date'], 'intervals.*.end_at' => ['required', 'date'], 'intervals.*.timezone' => ['required', 'timezone'], 'intervals.*.break_intervals' => ['nullable', 'array', 'max:10'], 'intervals.*.break_intervals.*.start_at' => ['required', 'date'], 'intervals.*.break_intervals.*.end_at' => ['required', 'date']],
            'export' => ['format' => ['required', 'in:csv,xlsx,pdf']],
            'uploadSignedFile' => $revision + ['document_id' => ['required', 'integer', 'min:1'], 'file' => ['required', 'file', 'mimetypes:application/pdf,image/png,image/jpeg', 'max:20480']],
            default => [],
        };
    }
}
