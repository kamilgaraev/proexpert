<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AccessRecertification;

use App\Rules\ActiveOrganizationUser;
use Illuminate\Foundation\Http\FormRequest;

final class AccessRecertificationReassignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reviewer_user_id' => [
                'required',
                'integer',
                new ActiveOrganizationUser((int) $this->attributes->get('current_organization_id')),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
