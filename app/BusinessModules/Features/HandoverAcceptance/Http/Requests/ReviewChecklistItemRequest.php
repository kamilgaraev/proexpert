<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ReviewChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['accepted', 'rejected'])],
            'comment' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => trans_message('handover_acceptance.validation.checklist_status_required'),
            'status.in' => trans_message('handover_acceptance.validation.checklist_status_invalid'),
            'comment.required_if' => trans_message('handover_acceptance.validation.checklist_rejection_comment_required'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(AdminResponse::error(
            trans_message('handover_acceptance.errors.validation_failed'),
            422,
            $validator->errors()->toArray(),
        ));
    }
}
