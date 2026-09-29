<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollPeriodRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_periods';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer'];
}
