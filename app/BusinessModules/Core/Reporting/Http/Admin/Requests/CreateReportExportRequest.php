<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use DateTimeZone;
use Illuminate\Validation\Rule;

final class CreateReportExportRequest extends ReportRunRouteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', 'in:csv,xlsx,pdf'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct:strict', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'sort_by' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'sort_dir' => ['required', 'in:asc,desc'],
            'locale' => ['required', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['format', 'columns', 'sort_by', 'sort_dir', 'locale', 'timezone'];
    }

    public function toData(): CreateReportExportData
    {
        return new CreateReportExportData(
            (string) $this->validated('format'),
            (array) $this->validated('columns'),
            new ReportWindowSort(
                (string) $this->validated('sort_by'),
                ReportSortDirection::from((string) $this->validated('sort_dir')),
            ),
            (string) $this->validated('locale'),
            new DateTimeZone((string) $this->validated('timezone')),
        );
    }
}
