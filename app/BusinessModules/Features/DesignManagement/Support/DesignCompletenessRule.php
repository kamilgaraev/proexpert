<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;

interface DesignCompletenessRule
{
    /**
     * @return DesignCompletenessRuleResult[]
     */
    public function check(DesignPackage $package): array;
}
