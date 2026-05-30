<?php

namespace App\Http\Requests\Api\V1\Admin\ContractorInvitation;

use App\Models\Contractor;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->currentOrganizationId();
        $user = $this->user();

        return $organizationId !== null
            && $user !== null
            && $user->belongsToOrganization($organizationId);
    }

    public function rules(): array
    {
        $organizationId = $this->currentOrganizationId();

        return [
            'invited_organization_id' => [
                'required',
                'integer',
                'exists:organizations,id',
                Rule::notIn($organizationId ? [$organizationId] : []),
                function ($attribute, $value, $fail) use ($organizationId) {
                    if ($organizationId === null) {
                        $fail(trans_message('contract.organization_context_missing'));
                        return;
                    }

                    $existingInvitation = ContractorInvitation::query()
                        ->where('organization_id', $organizationId)
                        ->where('invited_organization_id', $value)
                        ->active()
                        ->exists();

                    if ($existingInvitation) {
                        $fail(trans_message('contract.invitation_active_exists'));
                    }

                    $reverseInvitation = ContractorInvitation::query()
                        ->where('organization_id', $value)
                        ->where('invited_organization_id', $organizationId)
                        ->active()
                        ->exists();

                    if ($reverseInvitation) {
                        $fail(trans_message('contract.invitation_reverse_active_exists'));
                    }

                    $existingContractor = Contractor::query()
                        ->where('organization_id', $organizationId)
                        ->where('source_organization_id', $value)
                        ->exists();

                    if ($existingContractor) {
                        $fail(trans_message('contract.invitation_organization_already_contractor'));
                    }

                    $targetOrg = Organization::query()->find($value);
                    if (!$targetOrg || !$targetOrg->is_active) {
                        $fail(trans_message('contract.invitation_target_unavailable'));
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
            'invited_organization_id.required' => trans_message('contract.invitation_target_required'),
            'invited_organization_id.exists' => trans_message('contract.invitation_target_not_found'),
            'invited_organization_id.not_in' => trans_message('contract.invitation_self_not_allowed'),
            'message.max' => trans_message('contract.invitation_message_max'),
            'metadata.array' => trans_message('contract.invitation_metadata_array'),
            'metadata.max' => trans_message('contract.invitation_metadata_max'),
            'metadata.*.string' => trans_message('contract.invitation_metadata_value_string'),
            'metadata.*.max' => trans_message('contract.invitation_metadata_value_max'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('invited_organization_id') && $this->input('invited_organization_id') !== null) {
            $this->merge([
                'invited_organization_id' => (int) $this->input('invited_organization_id'),
            ]);
        }
    }

    private function currentOrganizationId(): ?int
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
