<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Exceptions;

use RuntimeException;

final class PaymentDocumentDeviationBlockedException extends RuntimeException
{
    /**
     * @param array<string, mixed> $deviationData
     */
    public function __construct(private readonly array $deviationData, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function deviationData(): array
    {
        return $this->deviationData;
    }
}
