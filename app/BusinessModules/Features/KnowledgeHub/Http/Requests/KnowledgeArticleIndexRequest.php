<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Requests;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KnowledgeArticleIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:80'],
            'kind' => [
                'nullable',
                'string',
                Rule::in(array_map(
                    static fn (KnowledgeArticleKind $kind): string => $kind->value,
                    KnowledgeArticleKind::cases(),
                )),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
