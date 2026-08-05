<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePersonalFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\\\\\\/]/'],
            'parent_path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
