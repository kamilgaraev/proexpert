<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\Filament\Support\FilamentPermission;
use App\Models\OrganizationResourceAllocation;
use App\Models\SystemAdmin;
use DomainException;
use Illuminate\Support\Facades\DB;

final class LegacyEstimatorReconciliationCandidates
{
    /** @return array<int,string> */
    public function forRestore(int $actorId, int $organizationId): array
    {
        $actor = SystemAdmin::query()->find($actorId);
        if ($actor === null || ! $actor->hasSystemPermission(FilamentPermission::PAYMENTS_MANAGE)) {
            throw new DomainException('legacy_estimator_financial_permission_required');
        }
        $restored = DB::table('ai_credit_ledger_entries')->where('organization_id', $organizationId)
            ->where('type', 'conversion_reversal')->get(['metadata'])->map(static fn (object $entry): int => (int) (json_decode($entry->metadata, true, 512, JSON_THROW_ON_ERROR)['reconciliation_id'] ?? 0))->all();

        return DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organizationId)
            ->whereNotIn('id', $restored)->orderBy('id')->get(['id', 'allocation_id', 'converted_units_minor'])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->id => trans_message('estimate_evaluation.legacy_restore_label',
                ['id' => $row->id, 'allocation' => $row->allocation_id])])->all();
    }

    /** @return array<int,string> */
    public function forOrganization(int $actorId, int $organizationId): array
    {
        $actor = SystemAdmin::query()->find($actorId);
        if ($actor === null || ! $actor->hasSystemPermission(FilamentPermission::PAYMENTS_MANAGE)) {
            throw new DomainException('legacy_estimator_financial_permission_required');
        }

        return OrganizationResourceAllocation::query()->where('organization_id', $organizationId)
            ->where('source', 'paid_addon')->where('limit_key', 'ai_estimates_month')->where('quantity', '>', 0)->active()
            ->whereNotIn('id', DB::table(ReconcileLegacyEstimatorPaidBalance::TABLE)->where('organization_id', $organizationId)->select('allocation_id'))
            ->orderBy('id')->get(['id', 'quantity'])->mapWithKeys(static fn (OrganizationResourceAllocation $allocation): array => [
                (int) $allocation->id => trans_message('estimate_evaluation.legacy_allocation_label',
                    ['id' => $allocation->id, 'quantity' => $allocation->quantity]),
            ])->all();
    }
}
