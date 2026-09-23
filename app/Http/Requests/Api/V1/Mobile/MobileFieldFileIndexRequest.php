<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;

final class MobileFieldFileIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'record_type' => ['nullable', 'in:project,completed_work,construction_journal_entry'],
            'record_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
