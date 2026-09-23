<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyExecutiveApprovedListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'approved_list_id' => ['required', 'integer', 'min:1'],
            'item_keys' => ['required', 'array', 'min:1', 'max:500'],
            'item_keys.*' => ['required', 'string', 'max:128', 'distinct'],
        ];
    }
}
