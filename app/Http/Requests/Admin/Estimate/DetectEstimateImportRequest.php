<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

class DetectEstimateImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация через middleware
    }

    public function rules(): array
    {
        return [
            'file_id' => ['required', 'string', 'uuid'],
            'suggested_header_row' => ['nullable', 'integer', 'min:1'],
            'worksheet_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Нормализуем file_id если пришел как массив
        if ($this->has('file_id') && is_array($this->input('file_id'))) {
            $this->merge([
                'file_id' => (string)($this->input('file_id')[0] ?? ''),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'file_id.required' => trans_message('estimate.import_file_id_required'),
            'suggested_header_row.integer' => trans_message('estimate.import_header_row_integer'),
            'suggested_header_row.min' => trans_message('estimate.import_header_row_min'),
        ];
    }
}

