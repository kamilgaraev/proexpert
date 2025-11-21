<?php

namespace App\Exceptions;

use RuntimeException;

class ImmutableDataException extends RuntimeException
{
    public function __construct(string $modelClass, string $action = 'modify')
    {
        parent::__construct("Cannot {$action} immutable model {$modelClass}. This record is read-only to ensure financial integrity.");
    }
}

