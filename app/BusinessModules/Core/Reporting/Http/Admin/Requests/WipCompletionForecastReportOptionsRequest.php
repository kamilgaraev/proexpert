<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class WipCompletionForecastReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return [
            ...$this->forbiddenClientFieldsRules(),
            'project_id' => ['prohibited'],
            'current_project_id' => ['prohibited'],
            'scope' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }
}
