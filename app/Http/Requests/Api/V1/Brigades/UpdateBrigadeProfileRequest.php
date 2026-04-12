<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrigadeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'team_size' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'contact_person' => ['sometimes', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'string', 'max:50'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'availability_status' => ['sometimes', Rule::in(BrigadeStatuses::availabilityStatuses())],
            'regions' => ['sometimes', 'array'],
            'regions.*' => ['string', 'max:255'],
            'specializations' => ['sometimes', 'array'],
            'specializations.*' => ['string', 'max:255'],
            'submit_for_review' => ['sometimes', 'boolean'],
        ];
    }
}
