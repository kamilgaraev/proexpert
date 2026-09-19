<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractOrganizationViewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractRevisionPaymentPlanService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly ContractOrganizationViewService $views) {}

    public function apply(User $actor, int $organizationId, int $contractId, int $activationId): array
    {
        $this->authorize($actor, $organizationId, 'payments.schedule.create');

        return DB::transaction(function () use ($actor, $organizationId, $contractId, $activationId): array {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
            $activation = DB::table('contract_revision_activations')->where('instance_id', $instance->id)->where('id', $activationId)->firstOrFail();
            if ($activation->status !== 'applied' || (int) $instance->effective_revision_id !== (int) $activation->revision_id) {
                throw new ContractBuilderException('contracts.payment_plan_revision_stale', 409);
            }
            $revision = DB::table('contract_builder_revisions')->where('id', $activation->revision_id)->firstOrFail();
            $parties = json_decode($revision->parties, true, 512, JSON_THROW_ON_ERROR);
            if (count(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId)) !== 1) {
                throw new AuthorizationException;
            }
            $existing = DB::table('contract_revision_payment_plans')->where('revision_id', $revision->id)->where('organization_id', $organizationId)->first();
            if ($existing !== null) {
                return $this->present($existing);
            }
            $plan = json_decode($activation->plan, true, 512, JSON_THROW_ON_ERROR);
            $financialBases = array_intersect_key($plan['bases'], array_flip(['price', 'advance', 'retention']));
            if ($financialBases === []) {
                throw new ContractBuilderException('contracts.payment_plan_no_assignments', 422);
            }
            $terms = array_intersect_key($plan['terms'], array_flip(['total_amount', 'currency', 'planned_advance_amount', 'warranty_retention_percentage', 'warranty_retention_calculation_type']));
            usort($parties, static fn (array $a, array $b): int => strcmp($a['side'], $b['side']));
            $conditions = ['terms' => $terms, 'payer' => $parties[0], 'payee' => $parties[1], 'due_date' => null];
            DB::table('contract_revision_payment_plans')->where('contract_id', $contractId)->where('organization_id', $organizationId)->where('is_current', true)->update(['is_current' => false]);
            $id = DB::table('contract_revision_payment_plans')->insertGetId([
                'contract_id' => $contractId, 'revision_id' => $revision->id, 'organization_id' => $organizationId,
                'created_by' => $actor->id, 'activation_id' => $activationId, 'content_hash' => $activation->content_hash,
                'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'bases' => json_encode((object) $financialBases, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $this->present(DB::table('contract_revision_payment_plans')->where('id', $id)->firstOrFail());
        });
    }

    public function state(User $actor, int $organizationId, int $contractId): array
    {
        $this->authorize($actor, $organizationId, 'payments.schedule.view');
        $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        $items = DB::table('contract_revision_payment_plans')->where('contract_id', $contractId)->where('organization_id', $organizationId)->orderByDesc('id')->limit(20)->get();

        return ['effective_revision_id' => $instance->effective_revision_id,
            'can_apply' => $this->authorization->can($actor, 'payments.schedule.create', ['organization_id' => $organizationId]),
            'items' => $items->map(fn (object $row): array => $this->present($row))->all()];
    }

    private function authorize(User $actor, int $organizationId, string $permission): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }

    private function present(object $row): array
    {
        return ['id' => (int) $row->id, 'revision_id' => (int) $row->revision_id, 'activation_id' => (int) $row->activation_id,
            'content_hash' => $row->content_hash, 'is_current' => (bool) $row->is_current, 'created_at' => $row->created_at,
            'conditions' => json_decode($row->conditions, true, 512, JSON_THROW_ON_ERROR), 'bases' => json_decode($row->bases, true, 512, JSON_THROW_ON_ERROR)];
    }
}
