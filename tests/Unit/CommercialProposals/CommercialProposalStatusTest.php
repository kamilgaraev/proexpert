<?php

declare(strict_types=1);

namespace Tests\Unit\CommercialProposals;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use PHPUnit\Framework\TestCase;

final class CommercialProposalStatusTest extends TestCase
{
    public function test_status_actions_follow_commercial_proposal_lifecycle(): void
    {
        $this->assertSame(
            ['update', 'create_version', 'request_approval', 'archive'],
            CommercialProposalStatus::DRAFT->availableActions()
        );
        $this->assertSame(
            ['approve', 'reject', 'archive'],
            CommercialProposalStatus::INTERNAL_REVIEW->availableActions()
        );
        $this->assertSame(
            ['send', 'export', 'create_version', 'archive'],
            CommercialProposalStatus::APPROVED->availableActions()
        );
        $this->assertSame(
            ['record_result', 'export', 'create_version', 'archive'],
            CommercialProposalStatus::SENT->availableActions()
        );
        $this->assertSame(
            ['export', 'create_version'],
            CommercialProposalStatus::ACCEPTED->availableActions()
        );
    }

    public function test_only_customer_result_and_cancelled_statuses_are_final(): void
    {
        $this->assertFalse(CommercialProposalStatus::DRAFT->isFinal());
        $this->assertFalse(CommercialProposalStatus::APPROVED->isFinal());
        $this->assertTrue(CommercialProposalStatus::ACCEPTED->isFinal());
        $this->assertTrue(CommercialProposalStatus::REJECTED->isFinal());
        $this->assertTrue(CommercialProposalStatus::EXPIRED->isFinal());
        $this->assertTrue(CommercialProposalStatus::CANCELLED->isFinal());
    }
}
