<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Exceptions;

use RuntimeException;

final class CommercialProposalWorkflowException extends RuntimeException
{
    /**
     * @param list<array{code:string,message:string}> $blockers
     */
    public function __construct(
        private readonly array $blockers,
        string $message = ''
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{code:string,message:string}>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
