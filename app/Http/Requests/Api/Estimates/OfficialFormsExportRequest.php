<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Estimates;

use Illuminate\Foundation\Http\FormRequest;

final class OfficialFormsExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['format' => ['required', 'in:xlsx,pdf']];
    }
}
