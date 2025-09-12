<?php

namespace App\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleDiscovered
{
    use Dispatchable, SerializesModels;

    public string $moduleSlug;
    public \DateTime $discoveredAt;

    public function __construct(string $moduleSlug)
    {
        $this->moduleSlug = $moduleSlug;
        $this->discoveredAt = new \DateTime();
    }
}
