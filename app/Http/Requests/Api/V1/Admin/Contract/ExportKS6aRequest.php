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
            'period_start' => ['nullable', 'date', 'required_with:period_end'],
            'period_end' => ['nullable', 'date', 'required_with:period_start', 'after_or_equal:period_start'],
        ];
    }

    public function exportFormat(): string
    {
        return $this->validated('format') ?? 'pdf';
    }

    public function periodStart(): ?string
    {
        $value = $this->validated('period_start') ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function periodEnd(): ?string
    {
        $value = $this->validated('period_end') ?? null;

        return $value !== null ? (string) $value : null;
    }
}
