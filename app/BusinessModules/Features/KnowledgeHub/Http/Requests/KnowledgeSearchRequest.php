<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Requests;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KnowledgeSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'surface' => ['nullable', 'string', Rule::in(KnowledgeSurface::values())],
            'category' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:80'],
            'module' => ['nullable', 'string', 'max:120'],
            'module_slug' => ['nullable', 'string', 'max:120'],
            'permission_key' => ['nullable', 'string', 'max:160'],
            'context_key' => ['nullable', 'string', 'max:180'],
            'clicked_article_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
