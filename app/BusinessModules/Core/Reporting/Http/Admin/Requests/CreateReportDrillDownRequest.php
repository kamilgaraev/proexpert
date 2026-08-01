<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;

final class CreateReportDrillDownRequest extends ReportRunRouteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'token' => ['required', 'string', 'max:4096'],
            'cursor' => ['present', 'nullable', 'string', 'max:2048'],
            'limit' => ['required', 'integer', 'between:1,100'],
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['token', 'cursor', 'limit'];
    }

    public function toDrillDown(): ReportDrillDownRequest
    {
        return new ReportDrillDownRequest(
            (string) $this->validated('token'),
            $this->validated('cursor'),
            (int) $this->validated('limit'),
        );
    }
}
