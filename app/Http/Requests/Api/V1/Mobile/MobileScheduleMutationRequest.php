<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class MobileScheduleMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'recordAssignmentFact' => [
                'status' => ['required', 'string', Rule::in(['done', 'partially_done', 'not_done'])],
                'completed_quantity' => ['nullable', 'numeric', 'min:0'],
                'actual_work_hours' => ['nullable', 'numeric', 'min:0'],
                'fact_comment' => ['nullable', 'string', 'max:2000'],
                'failure_reason' => ['nullable', 'required_if:status,not_done', 'string', 'max:2000'],
            ],
            'submitDailyPlan' => [
                'summary_comment' => ['nullable', 'string', 'max:1000'],
            ],
            'createLinkedConstraintAction' => [
                'comment' => ['nullable', 'string', 'max:1000'],
            ],
            default => [],
        };
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('schedule_management.validation_error'),
            422,
            $validator->errors(),
        ));
    }
}
