<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Closure;
use Illuminate\Database\ConnectionInterface;

final readonly class ContractAuditedMutationService
{
    public function __construct(
        private LegalDocumentAudit $audit,
        private ConnectionInterface $connection,
    ) {}

    public function update(
        Contract $contract,
        array $attributes,
        string $event,
        ?int $actorId,
        array $context = [],
        ?Closure $afterPersist = null,
    ): Contract {
        return $this->persistUpdate(
            $contract,
            $attributes,
            $this->snapshot($contract, array_keys($attributes)),
            $event,
            $actorId,
            $context,
            $afterPersist,
        );
    }

    private function persistUpdate(
        Contract $contract,
        array $attributes,
        array $before,
        string $event,
        ?int $actorId,
        array $context,
        ?Closure $afterPersist,
    ): Contract {
        return $this->connection->transaction(function () use (
            $contract,
            $attributes,
            $before,
            $event,
            $actorId,
            $context,
            $afterPersist,
        ): Contract {
            $contract->setConnection($this->connection->getName());
            $contract->update($attributes);
            $additionalContext = $afterPersist?->__invoke($contract) ?? [];
            $auditContext = array_merge($context, is_array($additionalContext) ? $additionalContext : []);
            $auditContext['before'] = $before;
            $auditContext['after'] = $this->snapshot($contract, array_keys($attributes));

            $this->audit->recordContractForActorId($event, $contract, $actorId, $auditContext);

            return $contract;
        }, 3);
    }

    public function saveDirty(
        Contract $contract,
        string $event,
        ?int $actorId,
        array $context = [],
        ?Closure $afterPersist = null,
    ): Contract {
        $attributes = $contract->getDirty();
        $before = [];
        foreach (array_keys($attributes) as $field) {
            $before[$field] = $contract->getOriginal($field);
        }

        return $this->persistUpdate($contract, $attributes, $before, $event, $actorId, $context, $afterPersist);
    }

    public function recordCreated(
        Contract $contract,
        ?int $actorId,
        array $context = [],
    ): void {
        $this->connection->transaction(function () use ($contract, $actorId, $context): void {
            $context['after'] = $this->snapshot($contract, array_keys($contract->getAttributes()));
            $this->audit->recordContractForActorId('create', $contract, $actorId, $context);
        }, 3);
    }

    public function syncCompletionStatus(Contract $contract, ?int $actorId, array $context = []): bool
    {
        $oldStatus = $contract->status;
        $targetStatus = null;
        if ($contract->completion_percentage >= 100 && $oldStatus === ContractStatusEnum::ACTIVE) {
            $targetStatus = ContractStatusEnum::COMPLETED;
        } elseif ($contract->completion_percentage > 0 && $oldStatus === ContractStatusEnum::DRAFT) {
            $targetStatus = ContractStatusEnum::ACTIVE;
        }
        if (! $targetStatus instanceof ContractStatusEnum) {
            return false;
        }

        $this->update(
            $contract,
            ['status' => $targetStatus],
            'completion_status_synced',
            $actorId,
            $context,
            function (Contract $mutated) use ($oldStatus, $targetStatus): array {
                $this->connection->afterCommit(static function () use ($mutated, $oldStatus, $targetStatus): void {
                    event(new \App\Events\ContractStatusChanged($mutated, $oldStatus->value, $targetStatus->value));
                });

                return [];
            },
        );

        return true;
    }

    public function delete(Contract $contract, ?int $actorId, array $context = []): bool
    {
        return $this->connection->transaction(function () use ($contract, $actorId, $context): bool {
            $context['before'] = $this->snapshot($contract, array_keys($contract->getAttributes()));
            $this->audit->recordContractForActorId('delete', $contract, $actorId, $context);

            return (bool) $contract->delete();
        }, 3);
    }

    private function snapshot(Contract $contract, array $fields): array
    {
        $snapshot = [];
        foreach ($fields as $field) {
            $snapshot[$field] = $contract->getAttribute($field);
        }

        return $snapshot;
    }
}
