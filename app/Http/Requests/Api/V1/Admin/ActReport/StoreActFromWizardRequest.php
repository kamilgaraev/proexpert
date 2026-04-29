<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

use function trans_message;

class StoreActFromWizardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer'],
            'project_id' => ['prohibited'],
            'act_document_number' => ['required', 'string', 'max:255'],
            'act_date' => ['required', 'date'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'description' => ['nullable', 'string'],
            'selected_works' => ['nullable', 'array'],
            'selected_works.*.completed_work_id' => ['required', 'integer'],
            'selected_works.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'manual_lines' => ['nullable', 'array'],
            'manual_lines.*.title' => ['required', 'string', 'max:255'],
            'manual_lines.*.unit' => ['nullable', 'string', 'max:64'],
            'manual_lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'manual_lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'manual_lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'manual_lines.*.manual_reason' => ['nullable', 'string', 'max:2000'],
            'override' => ['nullable', 'array'],
            'override.enabled' => ['nullable', 'boolean'],
            'override.reason' => ['nullable', 'string', 'max:2000'],
            'override.target' => ['nullable', 'in:schedule_missing,contract_missing,over_coverage,manual_act_line'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (empty($this->input('selected_works', [])) && empty($this->input('manual_lines', []))) {
                $validator->errors()->add(
                    'selected_works',
                    trans_message('act_reports.empty_act_not_allowed')
                );
            }
        });
    }
}
