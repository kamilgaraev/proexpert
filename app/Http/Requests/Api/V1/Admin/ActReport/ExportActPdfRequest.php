<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportActPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['mode' => ['sometimes', 'string', Rule::in(['official', 'preview'])]];
    }

    public function isPreview(): bool
    {
        return $this->validated('mode', 'official') === 'preview';
    }
}
