<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;

final readonly class GetProjectProductionScheduleSummaryTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_production_schedule_summary';

    public const DESCRIPTION = 'ai_assistant_production_schedule.description';

    public function __construct(AssistantProjectProductionScheduleReader $reader)
    {
        parent::__construct($reader);
    }

    public function getParametersSchema(): array
    {
        $schema = parent::getParametersSchema();
        $schema['properties']['period_start'] = $schema['properties']['period_end'] = [
            'type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'description' => trans_message('ai_assistant_production_schedule.period_parameter'),
        ];

        return $schema;
    }
}
