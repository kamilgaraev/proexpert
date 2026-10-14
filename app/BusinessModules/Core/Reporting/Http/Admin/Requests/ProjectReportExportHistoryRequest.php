<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class ProjectReportExportHistoryRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return ['cursor' => ['nullable', 'string', 'max:2048'], ...$this->forbiddenClientFieldsRules(),
            'project_id' => ['prohibited'], 'current_project_id' => ['prohibited'], 'scope' => ['prohibited'], 'actor_id' => ['prohibited']];
    }

    protected function acceptedQueryFields(): array { return ['cursor']; }
}
