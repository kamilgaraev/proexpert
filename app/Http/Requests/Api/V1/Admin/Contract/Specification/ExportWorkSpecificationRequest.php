<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract\Specification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExportWorkSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['format' => ['sometimes', Rule::in(['pdf', 'docx'])]];
    }
}
