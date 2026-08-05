<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

final class PortfolioLiquidityReportOptionsRequest extends ReportFormRequest
{
    public function rules(): array
    {
        return $this->forbiddenClientFieldsRules();
    }
}
