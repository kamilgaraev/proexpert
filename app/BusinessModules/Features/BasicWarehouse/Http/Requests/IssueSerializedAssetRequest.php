<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueSerializedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()->current_organization_id;

        return [
            'responsible_user_id' => [
                'required',
                'integer',
                Rule::exists('organization_user', 'user_id')->where(
                    static fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->where('is_active', true),
                ),
            ],
            'expected_return_at' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
