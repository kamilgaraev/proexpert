<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssistantRagReindexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organizationId = (int) ($this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id ?? 0);

        return [
            'project_id' => ['nullable', 'integer', 'min:1', Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'source_type' => ['nullable', 'string', 'max:80', Rule::in(app(RagSourceRegistry::class)->enabledSourceTypes())],
        ];
    }
}
