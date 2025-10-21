<?php

namespace App\Events;

use App\Models\Estimate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Estimate $estimate
    ) {}
}

