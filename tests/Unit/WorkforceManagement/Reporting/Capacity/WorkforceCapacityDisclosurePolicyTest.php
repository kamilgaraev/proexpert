<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityDisclosurePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityDisclosurePolicyTest extends TestCase
{
    #[Test]
    public function project_reader_never_receives_null_bucket_or_employee_lineage(): void
    {
        $policy = new WorkforceCapacityDisclosurePolicy;

        self::assertTrue($policy->canViewAggregate(['workforce.view'], 7, [101], 7, 101));
        self::assertFalse($policy->canViewAggregate(['workforce.view'], 7, [101], 7, null));
        self::assertFalse($policy->canAuditLineage(['workforce.view'], 7, [101], 7, 101));
    }

    #[Test]
    public function audit_drilldown_requires_exact_audit_permission_and_matching_scope(): void
    {
        $policy = new WorkforceCapacityDisclosurePolicy;

        self::assertTrue($policy->canAuditLineage(
            ['workforce.view', 'workforce.audit.view'],
            7,
            [101],
            7,
            101,
        ));
        self::assertFalse($policy->canAuditLineage(
            ['workforce.view', 'workforce.audit.view'],
            7,
            [101],
            8,
            101,
        ));
    }
}
