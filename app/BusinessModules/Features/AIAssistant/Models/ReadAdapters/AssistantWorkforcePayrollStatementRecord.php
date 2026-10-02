<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollStatementRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_statements';
    protected $casts = ['organization_id' => 'integer', 'total_hours' => 'decimal:2', 'gross_amount' => 'decimal:2'];
}
