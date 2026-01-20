<?php

namespace App\BusinessModules\Addons\AIEstimates\Events;

use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationHistory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateGenerationFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AIGenerationHistory $generation,
        public string $errorMessage
    ) {}
}
