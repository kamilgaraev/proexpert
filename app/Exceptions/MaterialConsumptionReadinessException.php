<?php

declare(strict_types=1);

namespace App\Exceptions;

final class MaterialConsumptionReadinessException extends BusinessLogicException
{
    /**
     * @param  list<array{code: string, message: string, work_id?: int|null, material_id?: int|null}>  $reasons
     */
    public function __construct(string $message, public readonly array $reasons)
    {
        parent::__construct($message, 422);
    }
}
