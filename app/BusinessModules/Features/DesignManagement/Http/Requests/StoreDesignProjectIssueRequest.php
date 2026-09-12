<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDesignProjectIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['required', 'string', Rule::in(['minor', 'major', 'critical'])],
            'assignee_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'package_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'artifact_id' => ['nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'sheet_id' => ['nullable', 'integer'],
            'bim_element_id' => ['nullable', 'string', 'max:255'],
            'point' => ['nullable', 'array:x,y,z'],
            'point.x' => ['required_with:point', 'numeric'],
            'point.y' => ['required_with:point', 'numeric'],
            'point.z' => ['required_with:point', 'numeric'],
            'camera' => ['nullable', 'array'],
            'round_id' => ['nullable', 'integer'],
            'model_set_revision_id' => ['nullable', 'integer'],
            'view_models' => ['nullable', 'array', 'min:1', 'max:100', 'prohibits:model_set_revision_id'],
            'view_models.*' => ['array:version_id,transform'],
            'view_models.*.version_id' => ['required', 'integer', 'min:1', 'distinct'],
            'view_models.*.transform' => ['required', 'array:shift,rotation'],
            'view_models.*.transform.shift' => ['required', 'array', 'size:3'],
            'view_models.*.transform.shift.*' => ['required', 'numeric'],
            'view_models.*.transform.rotation' => ['required', 'numeric'],
            'elements' => ['nullable', 'array', 'max:100'],
            'elements.*.version_id' => ['required_with:elements', 'integer'],
            'elements.*.element_id' => ['required_with:elements', 'integer'],
        ];
    }
}
