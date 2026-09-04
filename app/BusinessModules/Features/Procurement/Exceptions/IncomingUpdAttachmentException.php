<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Exceptions;

use DomainException;

final class IncomingUpdAttachmentException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
