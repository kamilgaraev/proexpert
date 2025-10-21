<?php

namespace App\Events;

use App\Models\Estimate;
use App\Models\EstimateImportHistory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateImported
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Estimate $estimate,
        public EstimateImportHistory $importHistory
    ) {}
}

