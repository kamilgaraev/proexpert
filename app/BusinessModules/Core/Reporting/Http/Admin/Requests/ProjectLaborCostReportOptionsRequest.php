<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostOptionsService;
use Illuminate\Validation\Rule;

final class ProjectLaborCostReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(ProjectLaborCostOptionsService::TYPES)],
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::prohibitedIf(fn (): bool => $this->query('type') === null),
            ],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000', Rule::prohibitedIf(fn (): bool => $this->query('type') === null)],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['type', 'search', 'page'];
    }

    public function type(): ?string
    {
        $type = $this->validated('type');

        return is_string($type) ? $type : null;
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) ? $search : null;
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }
}
