<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportDeliverySummaryReader;

final readonly class GetProjectReportDeliverySummaryTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_report_delivery_summary';
    public const DESCRIPTION = 'ai_assistant_operational.report_delivery_description';

    public function __construct(AssistantReportDeliverySummaryReader $reader) { parent::__construct($reader); }
}
