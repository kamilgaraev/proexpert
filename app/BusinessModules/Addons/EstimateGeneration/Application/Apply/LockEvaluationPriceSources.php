<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keeps accepted catalog prices and unit semantics stable through the publication transaction. */
final class LockEvaluationPriceSources
{
    public function lock(EstimateGenerationSession $session, array $positions): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new \LogicException('Evaluation price locks require the publication transaction.');
        }
        $references = array_values(array_unique(array_column(array_column($positions, 'price_snapshot'), 'source_reference')));
        sort($references, SORT_STRING);
        foreach ($references as $reference) {
            if (is_string($reference) && preg_match('/\Asupplier:([1-9][0-9]*):([1-9][0-9]*)\z/', $reference, $supplier) === 1) {
                $this->lockSupplier($session, (int) $supplier[1]);

                continue;
            }
            if (! is_string($reference) || preg_match('/\A(catalog|work_type):([1-9][0-9]*)\z/', $reference, $match) !== 1) {
                throw ValidationException::withMessages(['price' => 'Источник цены не поддерживает подтверждённую публикацию.']);
            }
            $query = $match[1] === 'catalog' ? EstimatePositionCatalog::query() : WorkType::query();
            $source = $query->where('organization_id', $session->organization_id)->where('is_active', true)->sharedLock()->find((int) $match[2]);
            $unit = $source === null ? null : MeasurementUnit::query()->whereKey($source->measurement_unit_id)
                ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $session->organization_id))
                ->sharedLock()->first();
            if ($source === null || $unit === null) {
                throw ValidationException::withMessages(['price' => 'Источник цены или его единица больше не доступны. Пересчитайте оценку.']);
            }
        }
    }

    private function lockSupplier(EstimateGenerationSession $session, int $versionId): void
    {
        $organizationId = (int) $session->organization_id;
        $version = \App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion::query()
            ->where('organization_id', $organizationId)->where('integrity_status', 'verified')->sharedLock()->find($versionId);
        $proposal = $version === null ? null : \App\BusinessModules\Features\Procurement\Models\SupplierProposal::query()
            ->where('organization_id', $organizationId)->whereIn('status', ['submitted', 'accepted'])->sharedLock()->find($version->supplier_proposal_id);
        $request = $proposal === null ? null : \App\BusinessModules\Features\Procurement\Models\SupplierRequest::query()
            ->where('organization_id', $organizationId)->sharedLock()->find($proposal->supplier_request_id);
        $purchase = $request === null ? null : \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::query()
            ->where('organization_id', $organizationId)->sharedLock()->find($request->purchase_request_id);
        $site = $purchase === null ? null : \App\BusinessModules\Features\SiteRequests\Models\SiteRequest::query()
            ->where('organization_id', $organizationId)->where('project_id', $session->project_id)->sharedLock()->find($purchase->site_request_id);
        $project = $site === null ? null : \App\Models\Project::query()->whereKey($site->project_id)->where('is_archived', false)->sharedLock()->first();
        if ($version === null || $proposal === null || $request === null || $purchase === null || $site === null || $project === null) {
            throw ValidationException::withMessages(['price' => 'Версия предложения или его принадлежность проекту изменилась. Пересчитайте оценку.']);
        }
    }
}
