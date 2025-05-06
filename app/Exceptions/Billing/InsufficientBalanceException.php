<?php

namespace App\Exceptions\Billing;

class InsufficientBalanceException extends BalanceException
{
    // Исключение для случаев нехватки средств на балансе
    public function __construct($message = "Insufficient balance.", $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 