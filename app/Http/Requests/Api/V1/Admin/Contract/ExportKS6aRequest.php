<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

class ExportKS6aRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'format' => ['nullable', 'in:xlsx,pdf'],
        ];
    }

    public function exportFormat(): string
    {
        return $this->validated('format') ?? 'pdf';
    }
}
