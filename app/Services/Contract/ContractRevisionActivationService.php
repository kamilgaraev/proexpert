<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractRevisionActivationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractBuilderInstanceService $instances,
        private readonly ContractRevisionTermsCompiler $compiler,
    ) {}

    public function schedule(User $actor, int $organizationId, int $contractId, int $number, string $hash, ?int $previousRevisionId, string $date, string $basis, string $key): array
    {
        $this->authorize($actor, $organizationId);
        if ($number < 1 || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || trim($basis) === '' || mb_strlen($basis) > 2000
            || trim($key) === '' || mb_strlen($key) > 191 || !(new ContractVariableDefinitionValidator)->date($date)) {
            throw new ContractBuilderException('contracts.activation_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $number, $hash, $previousRevisionId, $date, $basis], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $number, $hash, $previousRevisionId, $date, $basis, $key, $fingerprint): array {
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $revision = $this->instances->read($actor, $organizationId, $contractId, $number);
            $own = array_filter($revision['parties'], static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId);
            if (count($own) !== 1) {
                throw new AuthorizationException;
            }
            $existing = DB::table('contract_revision_activations')->where('instance_id', $instance->id)
                ->where('organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (!hash_equals($existing->fingerprint, $fingerprint)) {
                    $this->conflict();
                }
                return $this->present($existing);
            }
            $effectiveId = $instance->effective_revision_id === null ? null : (int) $instance->effective_revision_id;
            if (!ContractBuilderWorkflow::canRevise($contract, $instance) || $effectiveId !== $previousRevisionId
                || (int) $instance->current_revision_id !== (int) $revision['id'] || !hash_equals($revision['content_hash'], $hash)
                || $effectiveId === (int) $revision['id'] || $date < now()->toDateString()
                || DB::table('contract_revision_activations')->where('instance_id', $instance->id)->whereIn('status', ['scheduled', 'failed'])->exists()
                || DB::table('contract_revision_confirmations')->where('revision_id', $revision['id'])->where('content_hash', $hash)->distinct()->count('side') !== 2) {
                $this->conflict();
            }
            $plan = $this->compiler->compile($revision);
            $previous = [];
            foreach (array_keys($plan['terms']) as $field) {
                $previous[$field] = $contract->getRawOriginal($field);
            }
            $id = DB::table('contract_revision_activations')->insertGetId([
                'instance_id' => $instance->id, 'revision_id' => $revision['id'], 'previous_revision_id' => $effectiveId,
                'organization_id' => $organizationId, 'created_by' => $actor->id, 'content_hash' => $hash,
                'basis' => $basis, 'effective_date' => $date, 'request_key' => $key, 'fingerprint' => $fingerprint,
                'plan' => json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'previous_terms' => json_encode((object) $previous, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->present(DB::table('contract_revision_activations')->where('id', $id)->firstOrFail());
        });
    }

    public function state(User $actor, int $organizationId, int $contractId): array
    {
        $view = $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        $revision = DB::table('contract_builder_revisions')->where('id', $instance->current_revision_id)->firstOrFail();
        $pending = DB::table('contract_revision_activations')->where('instance_id', $instance->id)->whereIn('status', ['scheduled', 'failed'])->first();
        $items = DB::table('contract_revision_activations')->where('instance_id', $instance->id)->orderByDesc('id')->limit(20)
            ->get(['id', 'revision_id', 'previous_revision_id', 'organization_id', 'created_by', 'content_hash', 'basis', 'effective_date', 'status', 'attempts', 'last_error', 'applied_at', 'created_at', 'cancelled_at', 'cancellation_basis']);
        $own = array_filter(json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR),
            static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId);
        $allowed = (int) $actor->current_organization_id === $organizationId && count($own) === 1
            && $this->authorization->can($actor, 'contracts.revisions.activate', ['organization_id' => $organizationId]);

        return ['effective_revision_id' => $instance->effective_revision_id === null ? null : (int) $instance->effective_revision_id,
            'can_view_payment_plans' => $this->authorization->can($actor, 'payments.schedule.view', ['organization_id' => $organizationId]),
            'current_revision_id' => (int) $revision->id, 'content_hash' => $revision->content_hash,
            'can_activate' => $allowed && $pending === null && ContractBuilderWorkflow::canRevise($view->contract, $instance)
                && (int) $instance->effective_revision_id !== (int) $revision->id
                && DB::table('contract_revision_confirmations')->where('revision_id', $revision->id)->where('content_hash', $revision->content_hash)->distinct()->count('side') === 2,
            'can_retry' => $allowed && $pending !== null && $pending->effective_date <= now()->toDateString(),
            'can_cancel' => $allowed && $pending !== null,
            'items' => $items->map(fn (object $row): array => $this->present($row, false))->all()];
    }

    public function preview(User $actor, int $organizationId, int $contractId, int $number): array
    {
        $revision = $this->instances->read($actor, $organizationId, $contractId, $number);
        $plan = $this->compiler->compile($revision);
        $contract = Contract::findOrFail($contractId);
        $changes = [];
        foreach ($plan['terms'] as $field => $value) {
            $changes[] = ['field' => $field, 'before' => $contract->getRawOriginal($field), 'after' => $value];
        }

        return ['revision_id' => (int) $revision['id'], 'content_hash' => $revision['content_hash'],
            'plan' => ['terms' => (object) $plan['terms'], 'works' => $plan['works'], 'bases' => (object) $plan['bases']], 'changes' => $changes];
    }

    public function show(User $actor, int $organizationId, int $contractId, int $activationId): array
    {
        $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();

        return $this->present(DB::table('contract_revision_activations')->where('instance_id', $instance->id)->where('id', $activationId)->firstOrFail());
    }

    public function authorize(User $actor, int $organizationId): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.revisions.activate', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    public function cancel(User $actor, int $organizationId, int $contractId, int $activationId, string $basis, string $key): array
    {
        $this->authorize($actor, $organizationId);
        if (trim($basis) === '' || mb_strlen($basis) > 2000 || trim($key) === '' || mb_strlen($key) > 191) {
            throw new ContractBuilderException('contracts.activation_input_invalid', 422);
        }
        $fingerprint = hash('sha256', json_encode([$actor->id, $organizationId, $basis], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $activationId, $basis, $key, $fingerprint): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $row = DB::table('contract_revision_activations')->where('instance_id', $instance->id)->where('id', $activationId)->lockForUpdate()->firstOrFail();
            $parties = json_decode(DB::table('contract_builder_revisions')->where('id', $row->revision_id)->value('parties'), true, 512, JSON_THROW_ON_ERROR);
            if (count(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId)) !== 1) {
                throw new AuthorizationException;
            }
            if ($row->status === 'cancelled') {
                if ($row->cancellation_key !== $key || !hash_equals($row->cancellation_fingerprint, $fingerprint)) {
                    $this->conflict();
                }
                return $this->present($row);
            }
            if ($row->status === 'applied') {
                $this->conflict();
            }
            DB::table('contract_revision_activations')->where('id', $row->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancelled_organization_id' => $organizationId, 'cancellation_basis' => $basis,
                'cancellation_key' => $key, 'cancellation_fingerprint' => $fingerprint, 'updated_at' => now(),
            ]);

            return $this->present(DB::table('contract_revision_activations')->where('id', $row->id)->firstOrFail());
        });
    }

    public function present(object $row, bool $includePlan = true): array
    {
        $result = ['id' => (int) $row->id, 'revision_id' => (int) $row->revision_id,
            'previous_revision_id' => $row->previous_revision_id === null ? null : (int) $row->previous_revision_id,
            'organization_id' => (int) $row->organization_id, 'created_by' => (int) $row->created_by,
            'content_hash' => $row->content_hash, 'basis' => $row->basis, 'effective_date' => $row->effective_date,
            'status' => $row->status, 'attempts' => (int) $row->attempts, 'last_error' => $row->last_error,
            'error_message' => $row->last_error === null ? null : trans_message($row->last_error),
            'applied_at' => $row->applied_at, 'created_at' => $row->created_at,
            'cancelled_at' => $row->cancelled_at, 'cancellation_basis' => $row->cancellation_basis];
        if ($includePlan) {
            $result['plan'] = json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR);
            $result['result'] = $row->result === null ? null : json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    private function conflict(): never
    {
        throw new ContractBuilderException('contracts.activation_conflict', 409);
    }
}
