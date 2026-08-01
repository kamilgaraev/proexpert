<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;

final class GetReportRowsRequest extends ReportRunRouteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cursor' => ['present', 'nullable', 'string', 'max:2048'],
            'limit' => ['required', 'integer', 'between:1,100'],
            'sort_by' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'sort_dir' => ['required', 'in:asc,desc'],
        ];
    }

    protected function acceptedQueryFields(): array
    {
        return ['cursor', 'limit', 'sort_by', 'sort_dir'];
    }

    public function toWindow(): ReportRowsWindow
    {
        return new ReportRowsWindow(
            $this->validated('cursor'),
            (int) $this->validated('limit'),
            new ReportWindowSort(
                (string) $this->validated('sort_by'),
                ReportSortDirection::from((string) $this->validated('sort_dir')),
            ),
        );
    }
}
