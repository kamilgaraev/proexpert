<?php

namespace App\Http\Requests\Api\V1\Admin\ContractorInvitation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()->current_organization_id;

        return [
            'invited_organization_id' => [
                'required',
                'integer',
                'exists:organizations,id',
                Rule::notIn([$organizationId]),
                function ($attribute, $value, $fail) use ($organizationId) {
                    $existingInvitation = \App\Models\ContractorInvitation::where('organization_id', $organizationId)
                        ->where('invited_organization_id', $value)
                        ->active()
                        ->exists();

                    if ($existingInvitation) {
                        $fail('Активное приглашение для данной организации уже существует.');
                    }

                    $existingContractor = \App\Models\Contractor::where('organization_id', $organizationId)
                        ->where('source_organization_id', $value)
                        ->exists();

                    if ($existingContractor) {
                        $fail('Данная организация уже является вашим подрядчиком.');
                    }

                    $targetOrg = \App\Models\Organization::find($value);
                    if (!$targetOrg || !$targetOrg->is_active) {
                        $fail('Выбранная организация недоступна для приглашения.');
                    }
                },
            ],
            'message' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'metadata' => [
                'nullable',
                'array',
                'max:10',
            ],
            'metadata.*' => [
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'invited_organization_id.required' => 'Необходимо выбрать организацию для приглашения.',
            'invited_organization_id.exists' => 'Выбранная организация не найдена.',
            'invited_organization_id.not_in' => 'Нельзя пригласить собственную организацию.',
            'message.max' => 'Сообщение не должно превышать 1000 символов.',
            'metadata.array' => 'Метаданные должны быть в формате массива.',
            'metadata.max' => 'Количество метаданных не должно превышать 10.',
            'metadata.*.string' => 'Значения метаданных должны быть строками.',
            'metadata.*.max' => 'Значение метаданных не должно превышать 255 символов.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'invited_organization_id' => (int) $this->input('invited_organization_id'),
        ]);
    }
}