<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;

final class MobileBrigadeListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
