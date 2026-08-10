<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Support;

use Throwable;

final class OverduePaymentFailureContext
{
    /**
     * @return array{payment_document_id: int, exception_class: class-string<Throwable>, exception_code: int}
     */
    public static function from(int $documentId, Throwable $exception): array
    {
        return [
            'payment_document_id' => $documentId,
            'exception_class' => $exception::class,
            'exception_code' => (int) $exception->getCode(),
        ];
    }
}
