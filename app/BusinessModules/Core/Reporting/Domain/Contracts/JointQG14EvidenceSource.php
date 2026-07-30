<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;

interface JointQG14EvidenceSource
{
    public function execute(): JointQG14Evidence;
}
