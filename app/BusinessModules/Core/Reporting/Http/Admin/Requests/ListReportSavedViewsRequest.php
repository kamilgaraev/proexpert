<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow;

final class ListReportSavedViewsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return ['cursor' => ['present', 'nullable', 'string', 'max:2048'], 'limit' => ['required', 'integer', 'between:1,100'], 'report_code' => ['present', 'nullable', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/'], ...$this->forbiddenClientFieldsRules()];
    }

    protected function acceptedQueryFields(): array
    {
        return ['cursor', 'limit', 'report_code'];
    }

    public function window(): ReportSavedViewWindow
    {
        return new ReportSavedViewWindow($this->validated('cursor'), (int) $this->validated('limit'), $this->validated('report_code'));
    }
}
