<?php

namespace App\BusinessModules\Features\AIAssistant\Events;

use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemAnalysisStarted
{
    use Dispatchable, SerializesModels;

    public SystemAnalysisReport $report;

    /**
     * Create a new event instance.
     */
    public function __construct(SystemAnalysisReport $report)
    {
        $this->report = $report;
    }
}

