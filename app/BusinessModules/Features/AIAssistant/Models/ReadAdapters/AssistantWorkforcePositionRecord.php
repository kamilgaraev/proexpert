<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforcePositionRecord extends WorkforceReadModel
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'workforce_positions';
    protected $casts = ['organization_id' => 'integer'];
}
