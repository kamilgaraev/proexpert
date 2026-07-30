<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\ContractPerformanceAct;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class HoldingAcceptedWorkEventVersion extends Model
{
    public $timestamps = false;

    protected $table = 'holding_accepted_work_event_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'performance_act_id' => 'integer',
            'contract_id' => 'integer',
            'project_id' => 'integer',
            'organization_id' => 'integer',
            'active' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('accepted_work_event_immutable'));
        self::deleting(static fn (): never => throw new LogicException('accepted_work_event_immutable'));
    }

    public static function record(
        ContractPerformanceAct $act,
        bool $active,
        DateTimeInterface $occurredAt,
        ?string $idempotencyKey = null,
    ): self {
        $act->loadMissing('contract');
        $contract = $act->contract;
        if ($contract === null) {
            throw new InvalidArgumentException('accepted_work_contract_missing');
        }
        $payload = [
            'performance_act_id' => (int) $act->getKey(),
            'contract_id' => (int) $contract->getKey(),
            'project_id' => (int) $act->project_id,
            'organization_id' => (int) $contract->organization_id,
            'active' => $active,
            'amount' => (string) $act->amount,
            'status' => (string) $act->status,
            'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($payload));
        $eventKey = $idempotencyKey ?? self::deterministicEventKey(
            (int) $act->getKey(),
            (int) $contract->getKey(),
            (int) $act->project_id,
            (int) $contract->organization_id,
            $active,
            (string) $act->amount,
            (string) $act->status,
            $occurredAt->format(DateTimeInterface::ATOM),
        );
        $record = self::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                ...$payload,
                'recorded_at' => now(),
                'source_hash' => $sourceHash,
            ],
        );
        if (! hash_equals((string) $record->source_hash, $sourceHash)) {
            throw new InvalidArgumentException('accepted_work_event_conflict');
        }

        return $record;
    }

    public static function deterministicEventKey(
        int $performanceActId,
        int $contractId,
        int $projectId,
        int $organizationId,
        bool $active,
        string $amount,
        string $status,
        string $occurredAt,
    ): string {
        if (min($performanceActId, $contractId, $projectId, $organizationId) < 1
            || trim($amount) === ''
            || trim($status) === ''
            || trim($occurredAt) === '') {
            throw new InvalidArgumentException('accepted_work_event_identity_invalid');
        }

        return 'owner:performance-act:'.hash('sha256', CanonicalJson::encode([
            'active' => $active,
            'amount' => $amount,
            'contract_id' => $contractId,
            'occurred_at' => $occurredAt,
            'organization_id' => $organizationId,
            'performance_act_id' => $performanceActId,
            'project_id' => $projectId,
            'status' => $status,
        ]));
    }
}
