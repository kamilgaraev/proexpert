<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

class UploadSignedActFileRequest extends UploadActReportFileRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
