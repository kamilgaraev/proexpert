<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\Exceptions\BusinessLogicException;

final class WorkReworkException extends BusinessLogicException
{
    public function __construct(public readonly string $reason, int $status)
    {
        parent::__construct(trans_message('work_rework.errors.'.$reason), $status);
    }
}
