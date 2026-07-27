<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

abstract class ReportRouteRequest extends ReportFormRequest
{
    abstract protected function routeParameter(): string;

    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            '_route_id' => $this->route($this->routeParameter()),
        ];
    }

    public function rules(): array
    {
        return [
            '_route_id' => ['required', ...$this->canonicalUlidRules()],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        if ($field !== '_route_id') {
            return parent::safeValidationFieldKey($field);
        }

        return $this->routeParameter() === 'runId' ? 'run_id' : 'export_id';
    }

    final public function routeId(): string
    {
        return (string) $this->validated('_route_id');
    }
}
