<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScopeWorkQuantity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class WorkReworkFinancialImpact
{
    public function snapshot(AcceptanceScopeWorkQuantity $line): array
    {
        $work = $line->completedWork()->firstOrFail();
        $organizationId = (int) $line->organization_id;
        $projectId = (int) $line->project_id;

        $canonical = DB::table('performance_act_lines as lines')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'lines.performance_act_id')
            ->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->join('completed_works as works', 'works.id', '=', 'lines.completed_work_id')
            ->where('lines.completed_work_id', $work->id)
            ->where('lines.line_type', 'completed_work')
            ->whereNull('works.deleted_at')
            ->where('works.organization_id', $organizationId)
            ->where('works.project_id', $projectId)
            ->where('contracts.organization_id', $organizationId)
            ->where(static function ($query) use ($projectId): void {
                $query->whereNull('contracts.project_id')->orWhere('contracts.project_id', $projectId);
            })
            ->where('acts.project_id', $projectId)
            ->whereNull('acts.annulled_at')
            ->whereNotIn('acts.status', ['rejected', 'annulled', 'cancelled', 'canceled'])
            ->where(static function ($query): void {
                $query->whereIn('acts.status', ['approved', 'signed'])->orWhere('acts.is_approved', true);
            })
            ->selectRaw('acts.id, acts.act_document_number, acts.status, acts.signed_at, SUM(lines.quantity) AS quantity, SUM(lines.amount) AS amount, MAX(CASE WHEN lines.quantity IS NULL THEN 1 ELSE 0 END) AS quantity_unknown, MAX(CASE WHEN lines.amount IS NULL THEN 1 ELSE 0 END) AS amount_unknown')
            ->groupBy('acts.id', 'acts.act_document_number', 'acts.status', 'acts.signed_at')
            ->get()
            ->keyBy('id');

        $legacy = DB::table('performance_act_completed_works as links')
            ->join('contract_performance_acts as acts', 'acts.id', '=', 'links.performance_act_id')
            ->join('contracts', 'contracts.id', '=', 'acts.contract_id')
            ->join('completed_works as works', 'works.id', '=', 'links.completed_work_id')
            ->where('links.completed_work_id', $work->id)
            ->whereNull('works.deleted_at')
            ->where('works.organization_id', $organizationId)
            ->where('works.project_id', $projectId)
            ->where('contracts.organization_id', $organizationId)
            ->where(static function ($query) use ($projectId): void {
                $query->whereNull('contracts.project_id')->orWhere('contracts.project_id', $projectId);
            })
            ->where('acts.project_id', $projectId)
            ->whereNull('acts.annulled_at')
            ->whereNotIn('acts.status', ['rejected', 'annulled', 'cancelled', 'canceled'])
            ->where(static function ($query): void {
                $query->whereIn('acts.status', ['approved', 'signed'])->orWhere('acts.is_approved', true);
            })
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('performance_act_lines as canonical')
                    ->whereColumn('canonical.performance_act_id', 'links.performance_act_id')
                    ->whereColumn('canonical.completed_work_id', 'links.completed_work_id')
                    ->where('canonical.line_type', 'completed_work');
            })
            ->selectRaw('acts.id, acts.act_document_number, acts.status, acts.signed_at, SUM(links.included_quantity) AS quantity, SUM(links.included_amount) AS amount, MAX(CASE WHEN links.included_quantity IS NULL THEN 1 ELSE 0 END) AS quantity_unknown, MAX(CASE WHEN links.included_amount IS NULL THEN 1 ELSE 0 END) AS amount_unknown')
            ->groupBy('acts.id', 'acts.act_document_number', 'acts.status', 'acts.signed_at')
            ->get()
            ->keyBy('id');

        $canonicalIds = $canonical->keys()->map(static fn ($id): int => (int) $id)->flip();
        $acts = $canonical->concat($legacy)
            ->sortBy('id')
            ->map(static function (object $act): array {
                return [
                    'id' => (int) $act->id,
                    'number' => $act->act_document_number,
                    'status' => $act->status,
                    'signed_at' => $act->signed_at,
                    'quantity' => (int) $act->quantity_unknown === 1 || $act->quantity === null
                        ? null : (string) BigDecimal::of((string) $act->quantity),
                    'amount' => (int) $act->amount_unknown === 1 || $act->amount === null
                        ? null : (string) BigDecimal::of((string) $act->amount),
                    'quantity_unknown' => (int) $act->quantity_unknown === 1,
                    'amount_unknown' => (int) $act->amount_unknown === 1,
                    'financial_data_unknown' => (int) $act->quantity_unknown === 1 || (int) $act->amount_unknown === 1,
                ];
            })->values()->all();

        foreach ($acts as $index => $act) {
            $acts[$index]['source'] = $canonicalIds->has($act['id']) ? 'canonical' : 'legacy';
        }

        return [
            'correction_review_required' => $acts !== [],
            'acts' => $acts,
        ];
    }
}
