<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollStatementRowRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_statement_rows';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'hours' => 'decimal:2', 'gross_amount' => 'decimal:2'];
}
