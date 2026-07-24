<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

class UploadActReportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
