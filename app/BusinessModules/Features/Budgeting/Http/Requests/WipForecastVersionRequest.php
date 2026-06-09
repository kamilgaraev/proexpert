<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

final class WipForecastVersionRequest extends WipForecastReportRequest
{
    public function rules(): array
    {
        return array_merge($this->wipForecastRules(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }
}
