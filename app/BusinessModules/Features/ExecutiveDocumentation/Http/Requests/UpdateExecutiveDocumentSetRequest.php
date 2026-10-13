<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateExecutiveDocumentSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'stage_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'zone_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planned_transmittal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'project_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'status' => ['prohibited'],
            'set_number' => ['prohibited'],
            'approved_list_id' => ['prohibited'],
        ];
    }
}
