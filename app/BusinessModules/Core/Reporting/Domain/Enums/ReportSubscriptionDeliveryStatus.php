<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSubscriptionDeliveryStatus: string { case SCHEDULED = 'scheduled'; case BUILDING_RUN = 'building_run'; case BUILDING_EXPORT = 'building_export'; case READY = 'ready'; case NOTIFIED = 'notified'; case FAILED = 'failed'; case EXPIRED = 'expired'; }
