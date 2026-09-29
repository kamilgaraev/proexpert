<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models\ReadAdapters;

final class AssistantWorkforceOrderRecord extends WorkforceReadModel
{
    protected $table = 'workforce_orders';
    protected $casts = ['organization_id' => 'integer'];
}
