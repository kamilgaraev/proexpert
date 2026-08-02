<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Exceptions;

use DomainException;

final class NonMonotonicProcurementTimeline extends DomainException {}
