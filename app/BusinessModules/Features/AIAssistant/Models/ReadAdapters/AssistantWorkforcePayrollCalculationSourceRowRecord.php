<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollCalculationSourceRowRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_calculation_source_rows';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'hours' => 'decimal:4', 'rate' => 'decimal:4', 'amount' => 'decimal:4'];
}
