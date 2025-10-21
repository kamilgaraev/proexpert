<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
    
    protected function prepareForValidation(): void
    {
        // Normalize file_id if it's an array
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
            'file_id.required' => 'Не указан идентификатор файла',
            'matching_config.required' => 'Необходимо указать конфигурацию сопоставления',
            'matching_config.create_new_work_types.required' => 'Необходимо указать, создавать ли новые виды работ',
            'estimate_settings.required' => 'Необходимо указать настройки сметы',
            'estimate_settings.name.required' => 'Необходимо указать название сметы',
            'estimate_settings.type.required' => 'Необходимо указать тип сметы',
            'estimate_settings.type.in' => 'Недопустимый тип сметы',
        ];
    }
}

