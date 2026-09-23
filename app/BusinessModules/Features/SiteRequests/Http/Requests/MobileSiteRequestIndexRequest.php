<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MobileSiteRequestIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['urgent', 'overdue'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $normalized[$field] = strtolower($value) === 'true';
            }
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::in(['own', 'assigned', 'all', 'approvals'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'status' => ['sometimes', 'string', 'max:50'],
            'priority' => ['sometimes', 'string', Rule::enum(SiteRequestPriorityEnum::class)],
            'request_type' => ['sometimes', 'string', Rule::enum(SiteRequestTypeEnum::class)],
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
            'urgent' => ['sometimes', 'boolean'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned_to' => ['sometimes', 'nullable', 'regex:/^(?:[1-9][0-9]*|unassigned)$/'],
            'required_from' => ['sometimes', 'date'],
            'required_to' => ['sometimes', 'date', 'after_or_equal:required_from'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'overdue' => ['sometimes', 'boolean'],
        ];
    }
}
