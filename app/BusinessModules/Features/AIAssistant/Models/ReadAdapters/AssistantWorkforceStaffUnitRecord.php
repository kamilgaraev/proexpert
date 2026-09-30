<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceStaffUnitRecord extends WorkforceReadModel
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'workforce_staff_units';
    protected $casts = ['organization_id' => 'integer', 'headcount' => 'decimal:2', 'rate' => 'decimal:4', 'base_salary' => 'decimal:2'];
}
