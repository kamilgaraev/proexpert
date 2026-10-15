<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Data;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;

final readonly class BrigadeResponseApproval
{
    public function __construct(
        public BrigadeResponse $response,
        public BrigadeProjectAssignment $assignment,
    ) {}
}
