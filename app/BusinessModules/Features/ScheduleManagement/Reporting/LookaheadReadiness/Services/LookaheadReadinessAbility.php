<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

final class LookaheadReadinessAbility
{
    public const APPROVE_SCHEDULE_REVISION = 'schedule.readiness.schedule_revisions.approve';

    public const PUBLISH_POLICY = 'schedule.readiness.policies.publish';

    public const PUBLISH_COMMITMENT = 'schedule.readiness.commitments.publish';

    public const MANAGE_CONSTRAINTS = 'schedule.readiness.constraints.manage';

    public const APPROVE_WAIVER = 'schedule.readiness.waivers.approve';

    public const SEAL_EVALUATION = 'schedule.readiness.evaluations.seal';

    public const REPORT_VIEW = 'schedule.reports.lookahead_readiness.view';

    public const REPORT_EXPORT = 'schedule.reports.lookahead_readiness.export';
}
