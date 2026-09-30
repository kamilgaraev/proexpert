<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceAttendanceScanEventRecord extends WorkforceReadModel
{
    protected $table = 'workforce_attendance_scan_events';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer'];
}
