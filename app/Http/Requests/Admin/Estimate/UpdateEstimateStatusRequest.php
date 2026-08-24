<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstimateStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller via Policy
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in(['draft', 'in_review', 'approved', 'rejected', 'cancelled']),
            ],
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => trans_message('estimate.status_required'),
            'status.in' => trans_message('estimate.status_invalid'),
            'comment.max' => trans_message('estimate.status_comment_too_long'),
            'comment.required_if' => trans_message('estimate.status_rejection_reason_required'),
        ];
    }
}
