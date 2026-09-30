<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceBusinessTripRecord extends WorkforceReadModel
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'workforce_business_trips';
    protected $casts = ['organization_id' => 'integer', 'project_id' => 'integer'];
}
