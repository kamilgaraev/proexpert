<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExportContractRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['format' => ['required', Rule::in(['pdf', 'docx'])]];
    }
}
