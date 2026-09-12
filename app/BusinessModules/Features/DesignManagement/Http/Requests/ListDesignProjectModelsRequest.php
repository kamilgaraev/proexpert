<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListDesignProjectModelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['project_id' => ['required', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200']];
    }
}
