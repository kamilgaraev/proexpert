<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

class ReportSavedViewRouteRequest extends ReportFormRequest
{
    public function validationData(): array
    {
        return [...parent::validationData(), '_saved_view_id' => $this->route('savedViewId')];
    }

    public function rules(): array
    {
        return ['_saved_view_id' => ['required', 'string', ...$this->canonicalUlidRules()], ...$this->forbiddenClientFieldsRules()];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        return $field === '_saved_view_id' ? 'saved_view_id' : parent::safeValidationFieldKey($field);
    }

    public function savedViewId(): string
    {
        return (string) $this->validated('_saved_view_id');
    }
}
