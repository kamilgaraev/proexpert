<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceAttendanceCorrectionRecord extends WorkforceReadModel
{
    protected $table = 'workforce_attendance_corrections';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer', 'hours' => 'decimal:2'];
}
