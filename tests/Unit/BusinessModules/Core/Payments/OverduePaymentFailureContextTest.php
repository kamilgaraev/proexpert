<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Support\OverduePaymentFailureContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OverduePaymentFailureContextTest extends TestCase
{
    public function test_it_exposes_safe_exception_metadata_without_the_exception_message(): void
    {
        $context = OverduePaymentFailureContext::from(
            documentId: 40,
            exception: new RuntimeException('secret payment provider response', 503),
        );

        self::assertSame([
            'payment_document_id' => 40,
            'exception_class' => RuntimeException::class,
            'exception_code' => 503,
        ], $context);
    }
}
