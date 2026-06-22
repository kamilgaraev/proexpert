<?php

declare(strict_types=1);

namespace Tests\Unit\AccessRecertification;

use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationDecisionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccessRecertificationDecisionPolicyTest extends TestCase
{
    public function test_self_review_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('self_review_forbidden');

        (new AccessRecertificationDecisionPolicy())->assertCanDecide(15, 15, 'approve', []);
    }

    public function test_revoke_requires_reason_and_executor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revoke_requires_executor');

        (new AccessRecertificationDecisionPolicy())->assertCanDecide(10, 11, 'revoke', [
            'reason' => 'Доступ больше не нужен',
        ]);
    }

    public function test_exception_requires_expiration_and_controls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exception_requires_controls');

        (new AccessRecertificationDecisionPolicy())->assertCanDecide(10, 11, 'exception', [
            'reason' => 'Временная замена сотрудника',
            'valid_until' => '2026-07-30',
        ]);
    }

    public function test_valid_approve_decision_passes(): void
    {
        (new AccessRecertificationDecisionPolicy())->assertCanDecide(10, 11, 'approve', [
            'reason' => 'Доступ подтвержден руководителем',
            'next_review_at' => '2026-09-30',
        ]);

        $this->assertTrue(true);
    }
}
