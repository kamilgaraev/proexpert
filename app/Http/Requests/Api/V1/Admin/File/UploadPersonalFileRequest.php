<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\File;

use Illuminate\Foundation\Http\FormRequest;

final class UploadPersonalFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'],
            'parent_path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
