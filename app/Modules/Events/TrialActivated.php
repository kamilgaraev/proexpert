<?php

namespace App\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrialActivated
{
    use Dispatchable, SerializesModels;

    public int $organizationId;
    public string $moduleSlug;
    public int $trialDays;

    public function __construct(int $organizationId, string $moduleSlug, int $trialDays)
    {
        $this->organizationId = $organizationId;
        $this->moduleSlug = $moduleSlug;
        $this->trialDays = $trialDays;
    }
}

