<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceWorkScheduleDayRecord extends WorkforceReadModel
{
    protected $table = 'workforce_work_schedule_days';
    protected $casts = ['organization_id' => 'integer', 'planned_hours' => 'decimal:2'];
}
