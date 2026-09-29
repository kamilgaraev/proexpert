<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollCalculationVersionRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_calculation_versions';
    protected $casts = ['organization_id' => 'integer'];
}
