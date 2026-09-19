<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\ContractOrganizationView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractOrganizationViewService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function synchronizeNewContract(Contract $contract): void
    {
        DB::transaction(function () use ($contract): void {
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $organizations = $locked->parties()->whereNotNull('linked_organization_id')
                ->pluck('linked_organization_id')->push($locked->organization_id)->unique()->values();
            ContractOrganizationView::where('contract_id', $locked->id)
                ->whereNotIn('organization_id', $organizations)->whereNull('access_revoked_at')
                ->update(['access_revoked_at' => now()]);
            foreach ($organizations as $organizationId) {
                $view = ContractOrganizationView::firstOrCreate([
                    'contract_id' => $locked->id, 'organization_id' => $organizationId,
                ]);
                if ($view->access_revoked_at !== null) {
                    $view->update(['access_revoked_at' => null]);
                }
            }
        });
    }

    public function find(User $actor, int $organizationId, int $contractId): ContractOrganizationView
    {
        $this->authorize($actor, $organizationId, 'contracts.view');

        return DB::transaction(function () use ($organizationId, $contractId): ContractOrganizationView {
            Contract::withTrashed()->whereKey($contractId)->sharedLock()->firstOrFail();

            return ContractOrganizationView::where('organization_id', $organizationId)
                ->where('contract_id', $contractId)->whereNull('access_revoked_at')
                ->with(['contract.firstParty', 'contract.secondParty'])->firstOrFail();
        });
    }

    public function list(User $actor, int $organizationId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $this->authorize($actor, $organizationId, 'contracts.view');

        return DB::transaction(function () use ($organizationId, $filters) {
            $query = ContractOrganizationView::query()
                ->join('contracts', 'contracts.id', '=', 'contract_organization_views.contract_id')
                ->where('contract_organization_views.organization_id', $organizationId)
                ->whereNull('contract_organization_views.access_revoked_at')
                ->where('contract_organization_views.visibility', $filters['visibility'] ?? 'active')
                ->select('contract_organization_views.*');
            $search = trim((string) ($filters['search'] ?? ''));
            if ($search !== '') {
                $query->where(function ($query) use ($search): void {
                    $query->where('contracts.number', 'ilike', '%'.$search.'%')
                        ->orWhere('contracts.subject', 'ilike', '%'.$search.'%');
                });
            }

            $total = (clone $query)->count();
            $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 25)));
            $page = max(1, (int) ($filters['page'] ?? 1));
            $items = $query->orderByDesc('contract_organization_views.contract_id')
                ->lock('FOR SHARE OF contracts')->with(['contract.firstParty', 'contract.secondParty'])
                ->forPage($page, $perPage)->get();

            return new \Illuminate\Pagination\LengthAwarePaginator($items, $total, $perPage, $page);
        });
    }

    public function updateNotes(User $actor, int $organizationId, int $contractId, ?string $notes, int $version): ContractOrganizationView
    {
        $this->authorize($actor, $organizationId, 'contracts.edit');

        return DB::transaction(function () use ($organizationId, $contractId, $notes, $version): ContractOrganizationView {
            Contract::withTrashed()->whereKey($contractId)->sharedLock()->firstOrFail();
            $view = ContractOrganizationView::where('organization_id', $organizationId)
                ->where('contract_id', $contractId)->whereNull('access_revoked_at')->lockForUpdate()->firstOrFail();
            if ($view->version !== $version) {
                throw new \App\Exceptions\BusinessLogicException(trans_message('contracts.organization_view_conflict'), 409);
            }
            $view->update(['private_notes' => $notes, 'version' => $version + 1]);

            return $view->load(['contract.firstParty', 'contract.secondParty']);
        });
    }

    public function transition(User $actor, int $organizationId, int $contractId, string $action, int $version): ContractOrganizationView
    {
        [$from, $to, $permission] = match ($action) {
            'archive' => ['active', 'archived', 'contracts.archive'],
            'unarchive' => ['archived', 'active', 'contracts.archive'],
            'trash' => ['archived', 'trashed', 'contracts.trash'],
            'restore' => ['trashed', 'archived', 'contracts.trash'],
            default => throw new \App\Exceptions\BusinessLogicException(trans_message('contracts.organization_view_transition_invalid'), 422),
        };
        $this->authorize($actor, $organizationId, $permission);

        return DB::transaction(function () use ($actor, $action, $organizationId, $contractId, $version, $from, $to): ContractOrganizationView {
            Contract::withTrashed()->whereKey($contractId)->sharedLock()->firstOrFail();
            $view = ContractOrganizationView::where('organization_id', $organizationId)
                ->where('contract_id', $contractId)->whereNull('access_revoked_at')->lockForUpdate()->firstOrFail();
            if ($view->version === $version + 1 && $view->visibility === $to) {
                return $view->load(['contract.firstParty', 'contract.secondParty']);
            }
            if ($view->version !== $version) {
                throw new \App\Exceptions\BusinessLogicException(trans_message('contracts.organization_view_conflict'), 409);
            }
            if ($view->visibility !== $from) {
                throw new \App\Exceptions\BusinessLogicException(trans_message('contracts.organization_view_transition_invalid'), 409);
            }
            $view->update(['visibility' => $to, 'version' => $version + 1]);
            DB::table('contract_organization_view_events')->insert([
                'view_id' => $view->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action,
                'from_visibility' => $from, 'to_visibility' => $to,
                'version' => $version + 1, 'created_at' => now(),
            ]);

            return $view->load(['contract.firstParty', 'contract.secondParty']);
        });
    }

    public function history(User $actor, int $organizationId, int $contractId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::transaction(function () use ($actor, $organizationId, $contractId, $filters) {
            $view = $this->find($actor, $organizationId, $contractId);
            ContractOrganizationView::whereKey($view->id)->sharedLock()->firstOrFail();

            return DB::table('contract_organization_view_events')->where('view_id', $view->id)
                ->select(['id', 'actor_id', 'actor_name', 'action', 'from_visibility', 'to_visibility', 'version', 'created_at'])
                ->orderByDesc('version')->paginate(
                    max(1, min(50, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)),
                );
        });
    }

    private function authorize(User $actor, int $organizationId, string $permission): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
    }
}
