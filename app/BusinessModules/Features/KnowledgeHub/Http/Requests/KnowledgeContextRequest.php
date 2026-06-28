<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Requests;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KnowledgeContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'surface' => ['nullable', 'string', Rule::in(KnowledgeSurface::values())],
            'module' => ['nullable', 'string', 'max:120'],
            'module_slug' => ['nullable', 'string', 'max:120'],
            'permission_key' => ['nullable', 'string', 'max:160'],
            'context_key' => ['nullable', 'string', 'max:180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:8'],
        ];
    }
}
