<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class TimeTrackingRagSource extends ModelDomainRagSource
{
    public function sourceType(): string
    {
        return 'time_tracking';
    }

    public function entities(): array
    {
        return [
            'time_entry' => ['model' => \App\Models\TimeEntry::class, 'fields' => ['id','project_id','worker_name','work_date','hours_worked','volume_completed','title','description','status','is_billable']]
        ];
    }
}