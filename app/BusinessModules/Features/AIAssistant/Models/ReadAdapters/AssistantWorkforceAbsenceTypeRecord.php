<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceAbsenceTypeRecord extends WorkforceReadModel
{
    protected $table = 'workforce_absence_types';
    protected $casts = ['organization_id' => 'integer'];
}
