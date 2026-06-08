<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Exceptions;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetPeriodCloseBlocker;
use DomainException;

final class BudgetPeriodCloseBlockedException extends DomainException
{
    /**
     * @param list<BudgetPeriodCloseBlocker> $blockers
     */
    public function __construct(private readonly array $blockers)
    {
        parent::__construct(trans_message('budgeting.period_close.blocked'));
    }

    /**
     * @return list<array{code:string,message:string,severity:string,count:int,meta:array<string, mixed>}>
     */
    public function blockers(): array
    {
        return array_map(
            static fn (BudgetPeriodCloseBlocker $blocker): array => $blocker->toArray(),
            $this->blockers
        );
    }
}
