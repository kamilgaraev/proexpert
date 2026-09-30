<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceWorkScheduleRecord extends WorkforceReadModel
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'workforce_work_schedules';
    protected $casts = ['organization_id' => 'integer', 'hours_per_day' => 'decimal:2'];
}
