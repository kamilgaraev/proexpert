<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteBimConstructionProgressGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']];
    }
}
