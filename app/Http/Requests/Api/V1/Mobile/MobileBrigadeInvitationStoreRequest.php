<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MobileBrigadeInvitationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id;

        return [
            'brigade_id' => ['required', 'integer', Rule::exists('brigades', 'id')->where('verification_status', BrigadeStatuses::PROFILE_APPROVED)],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'message' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
