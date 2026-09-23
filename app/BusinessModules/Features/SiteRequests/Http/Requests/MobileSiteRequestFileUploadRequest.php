<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobileSiteRequestFileUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:10240']];
    }
}
