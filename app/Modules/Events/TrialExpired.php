<?php

namespace App\Modules\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\OrganizationModuleActivation;

class TrialExpired
{
    use Dispatchable, SerializesModels;

    public OrganizationModuleActivation $activation;

    public function __construct(OrganizationModuleActivation $activation)
    {
        $this->activation = $activation;
    }
}

