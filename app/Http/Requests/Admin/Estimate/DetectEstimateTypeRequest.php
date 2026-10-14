<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

class DetectEstimateTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Auth проверяется в middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file_id' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file_id.required' => trans_message('estimate.import_file_id_required'),
            'file_id.string' => trans_message('estimate.import_status_job_invalid'),
            'file_id.uuid' => trans_message('estimate.import_status_job_invalid'),
        ];
    }
}

