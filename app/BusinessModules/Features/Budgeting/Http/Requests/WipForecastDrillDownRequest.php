<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

final class WipForecastDrillDownRequest extends WipForecastReportRequest
{
    public function rules(): array
    {
        return array_merge($this->wipForecastRules(), [
            'drill_down_key' => ['required', 'string', 'max:2000'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ]);
    }
}
