<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement;

use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\RequestWorkflow;
use PHPUnit\Framework\TestCase;

final class EmployeeRequestWorkflowTest extends TestCase
{
    public function test_manager_approval_requires_separate_hr_validation(): void
    {
        self::assertSame('awaiting_hr', RequestWorkflow::next('submitted', 'approve'));
        self::assertSame('fulfilled', RequestWorkflow::next('awaiting_hr', 'hr_approve'));
        self::assertSame('rejected', RequestWorkflow::next('submitted', 'reject'));
        self::assertSame('rejected', RequestWorkflow::next('awaiting_hr', 'hr_reject'));
    }

    public function test_comments_preserve_workflow_status(): void
    {
        self::assertSame('submitted', RequestWorkflow::next('submitted', 'comment'));
        self::assertSame('awaiting_hr', RequestWorkflow::next('awaiting_hr', 'comment'));
    }
}
