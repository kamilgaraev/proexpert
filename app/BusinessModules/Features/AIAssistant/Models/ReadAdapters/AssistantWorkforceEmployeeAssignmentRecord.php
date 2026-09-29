<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceEmployeeAssignmentRecord extends WorkforceReadModel
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'workforce_employee_assignments';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'rate' => 'decimal:4'];
}
