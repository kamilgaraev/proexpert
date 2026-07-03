<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\OneCExchange;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveOneCConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in([
                    'accept_most',
                    'accept_' . 'pro' . 'helper',
                    'accept_one_c',
                    'manual_link',
                    'postpone',
                    'assign',
                    'close_obsolete',
                    'comment',
                ]),
            ],
            'expected_version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'assigned_to' => ['nullable', 'integer', 'min:1'],
            'postponed_until' => ['nullable', 'date', 'after:now'],
            'manual_reference' => ['nullable', 'array'],
            'manual_reference.local_type' => ['nullable', 'string', 'max:80'],
            'manual_reference.local_id' => ['nullable', 'string', 'max:120'],
            'manual_reference.external_id' => ['nullable', 'string', 'max:191'],
            'manual_reference.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'action' => trans_message('one_c_exchange.conflict_validation.attributes.action'),
            'expected_version' => trans_message('one_c_exchange.conflict_validation.attributes.expected_version'),
            'comment' => trans_message('one_c_exchange.conflict_validation.attributes.comment'),
            'assigned_to' => trans_message('one_c_exchange.conflict_validation.attributes.assigned_to'),
            'postponed_until' => trans_message('one_c_exchange.conflict_validation.attributes.postponed_until'),
            'manual_reference.local_type' => trans_message('one_c_exchange.conflict_validation.attributes.manual_reference_local_type'),
            'manual_reference.local_id' => trans_message('one_c_exchange.conflict_validation.attributes.manual_reference_local_id'),
            'manual_reference.external_id' => trans_message('one_c_exchange.conflict_validation.attributes.manual_reference_external_id'),
            'manual_reference.note' => trans_message('one_c_exchange.conflict_validation.attributes.manual_reference_note'),
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => trans_message('one_c_exchange.conflict_validation.messages.action_required'),
            'action.in' => trans_message('one_c_exchange.conflict_validation.messages.action_in'),
            'expected_version.required' => trans_message('one_c_exchange.conflict_validation.messages.expected_version_required'),
            'expected_version.min' => trans_message('one_c_exchange.conflict_validation.messages.expected_version_min'),
            'postponed_until.after' => trans_message('one_c_exchange.conflict_validation.messages.postponed_until_after'),
        ];
    }
}
