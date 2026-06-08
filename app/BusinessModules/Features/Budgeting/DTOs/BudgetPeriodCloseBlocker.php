<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetPeriodCloseBlocker
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $severity = 'blocking',
        public int $count = 1,
        public array $meta = []
    ) {
    }

    /**
     * @return array{code:string,message:string,severity:string,count:int,meta:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'count' => $this->count,
            'meta' => $this->meta,
        ];
    }
}
