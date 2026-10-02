<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePayrollCalculationTransitionRecord extends WorkforceReadModel
{
    protected $table = 'workforce_payroll_calculation_transitions';
    protected $casts = ['organization_id' => 'integer'];
}
