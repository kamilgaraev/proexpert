<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSubscriptionStatus: string { case ACTIVE = 'active'; case PAUSED = 'paused'; case DISABLED = 'disabled'; case DELETED = 'deleted'; }
