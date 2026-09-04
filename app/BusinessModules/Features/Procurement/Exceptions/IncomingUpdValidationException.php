<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Exceptions;

use DomainException;

final class IncomingUpdValidationException extends DomainException
{
    /**
     * @param  array<int, array{code: string, line_number?: string|null}>  $errors
     * @param  array<int, array{code: string}>  $warnings
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings = [],
    ) {
        parent::__construct('incoming_upd_validation_failed');
    }
}
