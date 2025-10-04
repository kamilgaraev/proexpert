<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие изменения данных контрактов
 * Используется для инвалидации финансового и предиктивного кеша
 */
class ContractDataChanged
{
    use Dispatchable, SerializesModels;

    public int $organizationId;
    public ?int $contractId;

    public function __construct(int $organizationId, ?int $contractId = null)
    {
        $this->organizationId = $organizationId;
        $this->contractId = $contractId;
    }
}

