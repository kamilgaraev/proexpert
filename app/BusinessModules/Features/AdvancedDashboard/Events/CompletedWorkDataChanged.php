<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие изменения выполненных работ
 * Используется для инвалидации KPI и финансового кеша
 */
class CompletedWorkDataChanged
{
    use Dispatchable, SerializesModels;

    public int $organizationId;
    public ?int $userId;

    public function __construct(int $organizationId, ?int $userId = null)
    {
        $this->organizationId = $organizationId;
        $this->userId = $userId;
    }
}

