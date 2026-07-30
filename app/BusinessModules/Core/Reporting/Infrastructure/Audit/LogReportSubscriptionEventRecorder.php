<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Audit;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use Illuminate\Support\Facades\Log;

final class LogReportSubscriptionEventRecorder implements ReportSubscriptionEventRecorder
{
    public function record(
        string $eventCode,
        ReportExecutionContext $context,
        string $subjectType,
        string $subjectId,
        int $transitionVersion,
        array $safeEvidence,
    ): void {
        Log::info('report_subscription_event', [
            'event_code' => $eventCode,
            'organization_id' => $context->scope->organizationId,
            'owner_id' => $context->actor->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'transition_version' => $transitionVersion,
            'correlation_id' => $context->correlationId(),
            'safe_evidence' => $safeEvidence,
        ]);
    }
}
