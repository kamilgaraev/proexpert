<?php

namespace App\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleActivated
{
    use Dispatchable, SerializesModels;

    public int $organizationId;
    public string $moduleSlug;
    public \DateTime $activatedAt;

    public function __construct(int $organizationId, string $moduleSlug)
    {
        $this->organizationId = $organizationId;
        $this->moduleSlug = $moduleSlug;
        $this->activatedAt = new \DateTime();
    }
}
