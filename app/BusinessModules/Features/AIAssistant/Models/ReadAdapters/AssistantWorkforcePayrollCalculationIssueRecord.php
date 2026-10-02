<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollCalculationIssueRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_calculation_issues';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer'];
}
