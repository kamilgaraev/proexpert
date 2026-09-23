<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileCreateSiteRequestFromTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
