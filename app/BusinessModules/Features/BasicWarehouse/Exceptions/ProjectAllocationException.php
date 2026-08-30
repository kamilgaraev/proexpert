<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Exceptions;

use DomainException;

final class ProjectAllocationException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
