<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListDesignProjectIssuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:80'],
            'version_id' => ['nullable', 'integer', 'min:1'],
            'package_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
