<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class BusinessLogicException extends Exception
{
    public function report()
    {
        return null;
    }

    public function render($request)
    {
        return false;
    }
}
