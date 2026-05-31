<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class ExecuteEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file_id' => ['required'],
            'matching_config' => ['required', 'array'],
            'matching_config.create_new_work_types' => ['required', 'boolean'],
            'matching_config.skip_unmatched' => ['sometimes', 'boolean'],
            'estimate_settings' => ['required', 'array'],
            'estimate_settings.name' => ['required', 'string', 'max:255'],
            'estimate_settings.type' => ['required', 'string', 'in:local,object,summary,contractual'],
            'estimate_settings.project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'estimate_settings.contract_id' => ['sometimes', 'nullable', 'integer', 'exists:contracts,id'],
            'estimate_settings.financial_mode' => ['sometimes', 'string', 'in:plain,organization_defaults,custom'],
            'estimate_settings.vat_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'estimate_settings.overhead_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'estimate_settings.profit_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'validate_only' => ['sometimes', 'boolean'],
        ];
    }
    
    protected function prepareForValidation(): void
    {
        $fileId = $this->input('file_id');
        if (is_array($fileId)) {
            $this->merge([
                'file_id' => $fileId[0] ?? '',
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'file_id.required' => trans_message('estimate.import_file_id_required'),
            'matching_config.required' => trans_message('estimate.import_matching_config_required'),
            'matching_config.create_new_work_types.required' => trans_message('estimate.import_create_work_types_required'),
            'estimate_settings.required' => trans_message('estimate.import_estimate_settings_required'),
            'estimate_settings.name.required' => trans_message('estimate.import_estimate_name_required'),
            'estimate_settings.type.required' => trans_message('estimate.import_estimate_type_required'),
            'estimate_settings.type.in' => trans_message('estimate.import_estimate_type_invalid'),
        ];
    }
}

