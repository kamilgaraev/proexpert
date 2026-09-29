<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollValidationIssueRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_validation_issues';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer'];
}
