<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;

final class MobileCatalogTemplateIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['per_page' => ['nullable', 'integer', 'min:1', 'max:50']];
    }
}
