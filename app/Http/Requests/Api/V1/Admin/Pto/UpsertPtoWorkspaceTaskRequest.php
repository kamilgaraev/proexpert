<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Pto;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertPtoWorkspaceTaskRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source_key' => ['required', 'string', 'max:160'],
            'title' => ['sometimes', 'string', 'max:255'],
            'project_id' => ['required', 'integer', 'min:1'],
            'responsible_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'document_set_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'requirement_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
