<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;

final class MobileCatalogCrmIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
            'owner_user_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'pipeline_code' => ['nullable', 'string', 'max:64'],
            'stage_code' => ['nullable', 'string', 'max:64'],
            'archived' => ['nullable', 'boolean'],
            'merged' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];

        if ($this->route('entity') === 'deals') {
            $rules['project_id'] = ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }
}
