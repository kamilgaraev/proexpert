<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums;

enum ReadinessEventType: string
{
    case CONSTRAINT_REGISTERED = 'constraint_registered';
    case CONSTRAINT_EVIDENCE_ATTACHED = 'constraint_evidence_attached';
    case CONSTRAINT_RESOLVED = 'constraint_resolved';
    case CONSTRAINT_REOPENED = 'constraint_reopened';
    case READINESS_EVALUATED = 'readiness_evaluated';
    case WAIVER_REQUESTED = 'waiver_requested';
    case WAIVER_APPROVED = 'waiver_approved';
    case WAIVER_REJECTED = 'waiver_rejected';
    case WAIVER_EXPIRED = 'waiver_expired';
    case WAIVER_REVOKED = 'waiver_revoked';
    case COMMITMENT_PUBLISHED = 'commitment_published';
    case COMMITMENT_SUPERSEDED = 'commitment_superseded';
    case COMMITMENT_WITHDRAWN = 'commitment_withdrawn';
}
