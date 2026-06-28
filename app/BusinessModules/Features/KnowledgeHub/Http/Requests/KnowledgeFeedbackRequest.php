<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Requests;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeFeedbackReaction;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KnowledgeFeedbackRequest extends FormRequest
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
            'article_id' => ['required', 'integer', 'exists:knowledge_articles,id'],
            'reaction' => ['required', 'string', Rule::in(KnowledgeFeedbackReaction::values())],
            'surface' => ['nullable', 'string', Rule::in(KnowledgeSurface::values())],
            'context_key' => ['nullable', 'string', 'max:180'],
            'module' => ['nullable', 'string', 'max:120'],
            'module_slug' => ['nullable', 'string', 'max:120'],
            'permission_key' => ['nullable', 'string', 'max:160'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
