<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSubscriptionFrequency: string { case DAILY = 'daily'; case WEEKLY = 'weekly'; case MONTHLY = 'monthly'; }
