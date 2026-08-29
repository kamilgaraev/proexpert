<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentDocumentStatusTest extends TestCase
{
    public function test_status_capabilities_follow_the_payment_document_lifecycle(): void
    {
        $this->assertSame(
            ['approved', 'scheduled', 'partially_paid'],
            $this->statusesMatching(fn (PaymentDocumentStatus $status): bool => $status->canBePaid())
        );
        $this->assertSame(
            ['draft', 'submitted', 'pending_approval', 'approved', 'scheduled'],
            $this->statusesMatching(fn (PaymentDocumentStatus $status): bool => $status->canBeCancelled())
        );
        $this->assertSame(
            ['draft'],
            $this->statusesMatching(fn (PaymentDocumentStatus $status): bool => $status->canBeEdited())
        );
        $this->assertSame(
            ['paid', 'rejected', 'cancelled'],
            $this->statusesMatching(fn (PaymentDocumentStatus $status): bool => $status->isFinal())
        );
        $this->assertSame(
            ['submitted', 'pending_approval', 'approved', 'scheduled', 'partially_paid'],
            $this->statusesMatching(fn (PaymentDocumentStatus $status): bool => $status->isActive())
        );
    }

    private function statusesMatching(callable $predicate): array
    {
        return array_values(array_map(
            static fn (PaymentDocumentStatus $status): string => $status->value,
            array_filter(PaymentDocumentStatus::cases(), $predicate)
        ));
    }
}
