<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class CreateReportSavedViewRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return ['report_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/'], 'name' => ['required', 'string', 'max:120'], 'visibility' => ['required', 'in:private,organization'], 'filters' => ['required', 'array'], 'comparison' => ['present', 'array'], 'sort' => ['required', 'array:field,direction'], 'sort.field' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'], 'sort.direction' => ['required', 'in:asc,desc'], 'columns' => ['required', 'array', 'min:1'], 'columns.*' => ['string', 'distinct'], 'is_default' => ['required', 'boolean'], ...$this->forbiddenClientFieldsRules()];
    }

    protected function acceptedBodyFields(): array
    {
        return ['report_code', 'name', 'visibility', 'filters', 'comparison', 'sort', 'columns', 'is_default'];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
