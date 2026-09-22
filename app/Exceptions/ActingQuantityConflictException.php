<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ActingQuantityConflictException extends BusinessLogicException
{
    public function __construct(
        public readonly int $completedWorkId,
        public readonly string $availableToAct,
        public readonly string $requestedQuantity,
    ) {
        parent::__construct(
            trans_message('act_reports.acting_quantity_conflict', [
                'available' => $this->availableToAct,
            ]),
            422,
        );
    }

    /**
     * @return array{conflict: array{completed_work_id: int, available_to_act: string, requested_quantity: string}}
     */
    public function payload(): array
    {
        return [
            'conflict' => [
                'completed_work_id' => $this->completedWorkId,
                'available_to_act' => $this->availableToAct,
                'requested_quantity' => $this->requestedQuantity,
            ],
        ];
    }
}
