<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSubscriptionTrigger: string { case CALENDAR = 'calendar'; case MANUAL = 'manual'; }
