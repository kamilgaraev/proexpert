<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrigadeDocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_status' => ['required', Rule::in(BrigadeStatuses::documentStatuses())],
            'verification_notes' => ['nullable', 'string'],
        ];
    }
}
