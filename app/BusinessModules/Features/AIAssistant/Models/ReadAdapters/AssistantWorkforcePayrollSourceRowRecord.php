<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollSourceRowRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_source_rows';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'hours' => 'decimal:2', 'amount' => 'decimal:2'];
}
