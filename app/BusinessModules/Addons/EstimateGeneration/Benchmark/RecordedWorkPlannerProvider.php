<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlannerProvider;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlannerResponseData;

final readonly class RecordedWorkPlannerProvider implements WorkPlannerProvider
{
    public function __construct(private RecordedPortEnvelope $envelope)
    {
        if ($envelope->port !== RecordedPort::WorkPlanningModel) {
            throw new RecordedPortEnvelopeException('recorded_work_planner_port_invalid');
        }
    }

    public function provide(): WorkPlannerResponseData
    {
        return RecordedWorkPlannerResponseData::fromProviderArray($this->envelope->payload)->toWorkPlannerResponse();
    }
}
