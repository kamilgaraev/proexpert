<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccessRecertificationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'revoke', 'exception'])],
            'reason' => ['required', 'string', 'max:2000'],
            'confirmation' => ['sometimes', 'accepted'],
            'valid_until' => ['required_if:decision,exception', 'nullable', 'date', 'after:today'],
            'next_review_at' => ['sometimes', 'nullable', 'date', 'after:today'],
            'revoke_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'revoke_executor_user_id' => ['required_if:decision,revoke', 'nullable', 'integer', 'exists:users,id'],
            'evidence_notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'compensating_controls' => ['required_if:decision,exception', 'array'],
            'compensating_controls.*' => ['string', 'max:500'],
            'linked_sod_rule_ids' => ['sometimes', 'array'],
            'linked_sod_rule_ids.*' => ['string', 'max:120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (in_array($this->input('decision'), ['revoke', 'exception'], true) && !$this->boolean('confirmation')) {
                $validator->errors()->add('confirmation', trans_message('access_recertification.confirmation_required'));
            }
        });
    }
}
