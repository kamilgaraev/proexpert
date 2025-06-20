<?php

namespace App\Exceptions;

class ContractException extends BusinessLogicException
{
    public static function contractCompleted(): self
    {
        return new self('Контракт завершен. Добавление новых работ невозможно.', 422);
    }

    public static function contractTerminated(): self
    {
        return new self('Контракт расторгнут. Добавление новых работ невозможно.', 422);
    }

    public static function amountExceedsLimit(float $currentAmount, float $limitAmount, float $attemptedAmount): self
    {
        return new self(
            "Превышен лимит контракта. Текущая сумма: {$currentAmount} руб., лимит: {$limitAmount} руб., попытка добавить: {$attemptedAmount} руб.",
            422
        );
    }

    public static function contractNearingLimit(float $percentage): self
    {
        return new self("Внимание! Контракт выполнен на {$percentage}%. Приближение к лимиту.", 200);
    }
} 