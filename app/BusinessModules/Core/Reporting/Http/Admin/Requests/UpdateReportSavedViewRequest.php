<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class UpdateReportSavedViewRequest extends ReportSavedViewRouteRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'name' => ['sometimes', 'string', 'max:120'], 'visibility' => ['sometimes', 'in:private,organization'], 'filters' => ['sometimes', 'array'], 'comparison' => ['sometimes', 'array'], 'sort' => ['sometimes', 'array:field,direction'], 'sort.field' => ['required_with:sort', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'], 'sort.direction' => ['required_with:sort', 'in:asc,desc'], 'columns' => ['sometimes', 'array', 'min:1'], 'columns.*' => ['string', 'distinct'], ...$this->forbiddenClientFieldsRules()];
    }

    protected function acceptedBodyFields(): array
    {
        return ['name', 'visibility', 'filters', 'comparison', 'sort', 'columns'];
    }

    public function payload(): array
    {
        return $this->safe()->only($this->acceptedBodyFields());
    }
}
