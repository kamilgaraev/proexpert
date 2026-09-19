<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ListContractLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', 'in:template,block,variable'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'archived' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
