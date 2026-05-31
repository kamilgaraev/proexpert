<?php

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class MapEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Allow both string and array (will be normalized in controller)
            'file_id' => ['required'],
            // column_mapping может быть пустым - тогда вернем автоматический маппинг
            'column_mapping' => ['present', 'array'],
            'column_mapping.*' => [
                'nullable',
                'string',
            ],
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
            'file_id.required' => trans_message('estimate.import_file_id_required'),
            'column_mapping.present' => trans_message('estimate.import_column_mapping_required'),
            'column_mapping.array' => trans_message('estimate.import_column_mapping_invalid'),
        ];
    }
}

