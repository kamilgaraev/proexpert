<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\ContractOrganizationView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractOrganizationEnrollmentService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly ContractOrganizationViewService $views) {}

    public function preview(User $actor, int $organizationId, int $contractId): array
    {
        $this->authorize($actor, $organizationId);

        return DB::transaction(function () use ($organizationId, $contractId): array {
            $contract = Contract::where('organization_id', $organizationId)->whereKey($contractId)->sharedLock()->firstOrFail();
            $parties = $contract->parties()->orderBy('side')->get();
            $problem = $this->compatibilityProblem($contract, $parties->toArray());
            $enabled = $contract->organizationViews()->exists();

            return [
                'contract_id' => $contract->id, 'number' => $contract->number,
                'already_enabled' => $enabled,
                'can_enable' => !$enabled && $problem === null, 'reason' => $problem,
                'fingerprint' => $this->fingerprint($contract, $parties->toArray()),
                'restored_status' => $this->archiveEvidence($contract)?->metadata['from_status'] ?? null,
                'parties' => $parties->map(fn ($party): array => [
                    'side' => $party->side->value, 'name' => $party->name, 'inn' => $party->inn,
                    'linked_organization_id' => $party->linked_organization_id,
                ])->all(),
            ];
        });
    }

    public function enable(User $actor, int $organizationId, int $contractId, string $fingerprint, string $reason): ContractOrganizationView
    {
        $this->authorize($actor, $organizationId);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000 || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new BusinessLogicException(trans_message('contracts.enrollment_input_invalid'), 422);
        }

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $fingerprint, $reason): ContractOrganizationView {
            $contract = Contract::where('organization_id', $organizationId)->whereKey($contractId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('contract_organization_enrollments')->where('contract_id', $contractId)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->fingerprint, $fingerprint) || $existing->reason !== $reason) {
                    throw new BusinessLogicException(trans_message('contracts.organization_view_conflict'), 409);
                }

                return $this->views->find($actor, $organizationId, $contractId);
            }
            $parties = $contract->parties()->orderBy('side')->get()->toArray();
            if ($contract->organizationViews()->exists() || !hash_equals($this->fingerprint($contract, $parties), $fingerprint)) {
                throw new BusinessLogicException(trans_message('contracts.organization_view_conflict'), 409);
            }
            $problem = $this->compatibilityProblem($contract, $parties);
            if ($problem !== null) {
                throw new BusinessLogicException($problem, 409);
            }
            DB::table('contract_organization_enrollments')->insert([
                'contract_id' => $contractId, 'organization_id' => $organizationId,
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'fingerprint' => $fingerprint, 'reason' => $reason,
                'original_status' => $contract->status->value,
                'party_snapshots' => json_encode($parties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
            $this->views->synchronizeNewContract($contract);

            $archive = $this->archiveEvidence($contract);
            if ($archive !== null) {
                $restoredStatus = $archive->metadata['from_status'];
                app(ContractAuditedMutationService::class)->update(
                    $contract, ['status' => $restoredStatus], 'organization_view_enrollment', $actor->id,
                    ['archive_event_id' => $archive->id, 'reason' => $reason],
                );
                app(ContractStateEventService::class)->createStatusTransitionEvent(
                    $contract, 'organization_view_enrollment', 'archived', $restoredStatus, $reason, $actor->id,
                );
                $view = $this->views->find($actor, $organizationId, $contractId);

                return $this->views->transition($actor, $organizationId, $contractId, 'archive', $view->version);
            }

            return $this->views->find($actor, $organizationId, $contractId);
        });
    }

    private function fingerprint(Contract $contract, array $parties): string
    {
        return hash('sha256', json_encode([$contract->getRawOriginal(), $parties, $this->archiveEvidence($contract)?->getRawOriginal()], JSON_THROW_ON_ERROR));
    }

    private function compatibilityProblem(Contract $contract, array $parties): ?string
    {
        if ($contract->status->value === 'archived' && $this->archiveEvidence($contract) === null) {
            return trans_message('contracts.enrollment_archived_review');
        }
        if (count($parties) !== 2 || array_column($parties, 'side') !== ['first', 'second']
            || !in_array((int) $contract->organization_id, array_column($parties, 'linked_organization_id'), true)
            || collect($parties)->contains(fn (array $party): bool => trim((string) $party['name']) === '')) {
            return trans_message('contracts.enrollment_parties_review');
        }

        return null;
    }

    private function archiveEvidence(Contract $contract): ?ContractStateEvent
    {
        if ($contract->status !== ContractStatusEnum::ARCHIVED) {
            return null;
        }
        $event = ContractStateEvent::where('contract_id', $contract->id)
            ->where('event_type', ContractStateEventTypeEnum::STATUS_TRANSITION->value)
            ->orderByDesc('id')->sharedLock()->first();
        $metadata = $event?->metadata ?? [];
        $from = ContractStatusEnum::tryFrom((string) ($metadata['from_status'] ?? ''));
        if ($event === null || !$event->isActive() || ($metadata['action'] ?? null) !== 'archive'
            || ($metadata['to_status'] ?? null) !== 'archived'
            || $from === null || $from === ContractStatusEnum::ARCHIVED) {
            return null;
        }

        return $event;
    }

    private function authorize(User $actor, int $organizationId): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])
            || !$this->authorization->can($actor, 'contracts.view', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }
}
