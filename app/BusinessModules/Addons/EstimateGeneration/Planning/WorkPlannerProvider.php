<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

interface WorkPlannerProvider
{
    public function provide(): WorkPlannerResponseData;
}
