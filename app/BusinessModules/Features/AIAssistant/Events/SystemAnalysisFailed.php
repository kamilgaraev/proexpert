<?php

namespace App\BusinessModules\Features\AIAssistant\Events;

use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemAnalysisFailed
{
    use Dispatchable, SerializesModels;

    public SystemAnalysisReport $report;
    public string $errorMessage;

    /**
     * Create a new event instance.
     */
    public function __construct(SystemAnalysisReport $report, string $errorMessage)
    {
        $this->report = $report;
        $this->errorMessage = $errorMessage;
    }
}

