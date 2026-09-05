<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SecuritySessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        return [
            'group' => ['sometimes', 'string', 'in:all,active,history'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
