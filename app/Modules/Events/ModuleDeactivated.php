<?php

namespace App\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleDeactivated
{
    use Dispatchable, SerializesModels;

    public int $organizationId;
    public string $moduleSlug;
    public \DateTime $deactivatedAt;

    public function __construct(int $organizationId, string $moduleSlug)
    {
        $this->organizationId = $organizationId;
        $this->moduleSlug = $moduleSlug;
        $this->deactivatedAt = new \DateTime();
    }
}
