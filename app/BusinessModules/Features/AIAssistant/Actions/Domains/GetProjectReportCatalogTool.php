<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectReportCatalogReader;

final readonly class GetProjectReportCatalogTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_report_catalog';
    public const DESCRIPTION = 'ai_assistant_operational.report_catalog_description';
    public function __construct(AssistantProjectReportCatalogReader $reader) { parent::__construct($reader); }
}
