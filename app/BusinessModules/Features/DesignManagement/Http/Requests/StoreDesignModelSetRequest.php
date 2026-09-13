<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDesignModelSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'version_ids' => ['required', 'array', 'min:1', 'max:100'],
            'version_ids.*' => ['integer', 'distinct'],
            'transforms' => ['nullable', 'array', 'max:100'],
            'transforms.*' => ['array:shift,rotation'],
            'transforms.*.shift' => ['nullable', 'array:0,1,2'],
            'transforms.*.shift.*' => ['numeric'],
            'transforms.*.rotation' => ['nullable', 'numeric', 'between:-360,360'],
        ];
    }
}
