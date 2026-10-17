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
        $normative = array_values(array_filter($positions, static fn (array $position): bool => ($position['price_snapshot']['source_type'] ?? null) === 'normative'));
        if ($normative !== []) {
            $this->lockNormative($session, $normative);
        }
        $commercial = array_values(array_filter($positions, static fn (array $position): bool => ($position['price_snapshot']['source_type'] ?? null) !== 'normative'));
        $references = array_values(array_unique(array_column(array_column($commercial, 'price_snapshot'), 'source_reference')));
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

    private function lockNormative(EstimateGenerationSession $session, array $positions): void
    {
        $priceIds = $versionIds = $normIds = [];
        $items = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkScope)->items($session->draft_payload ?? []);
        foreach ($positions as $position) {
            $price = $position['price_snapshot'];
            $normId = $items[$position['key']]['item']['normative_match']['norm_id'] ?? null;
            if (! is_int($normId) || $normId < 1 || ! is_int($price['price_version_id'] ?? null)
                || ! is_array($price['resource_sources'] ?? null) || $price['resource_sources'] === []) {
                $this->rejectNormative();
            }
            $normIds[$normId] = $normId;
            $versionIds[$price['price_version_id']] = $price['price_version_id'];
            foreach ($price['resource_sources'] as $resource) {
                if (! is_string($resource['source_reference'] ?? null)
                    || preg_match('/\Aestimate_resource_prices:([1-9][0-9]*)\z/', $resource['source_reference'], $match) !== 1) {
                    $this->rejectNormative();
                }
                $priceIds[(int) $match[1]] = (int) $match[1];
            }
        }
        $norms = DB::table('estimate_norms')->whereIn('id', $normIds)->orderBy('id')->sharedLock()->get(['id', 'collection_id']);
        $collections = DB::table('estimate_norm_collections')->whereIn('id', $norms->pluck('collection_id'))
            ->orderBy('id')->sharedLock()->get(['id', 'dataset_version_id']);
        DB::table('estimate_norm_resources')->whereIn('estimate_norm_id', $normIds)->orderBy('id')->sharedLock()->get(['id']);
        $versions = DB::table('estimate_regional_price_versions')->whereIn('id', $versionIds)->whereIn('status', ['active', 'checked'])
            ->orderBy('id')->sharedLock()->get(['id']);
        $prices = DB::table('estimate_resource_prices')->whereIn('id', $priceIds)->orderBy('id')->sharedLock()->get(['id', 'dataset_version_id']);
        $datasets = $collections->pluck('dataset_version_id')->merge($prices->pluck('dataset_version_id'))->unique()->values();
        $parsed = DB::table('estimate_dataset_versions')->whereIn('id', $datasets)->where('status', 'parsed')
            ->orderBy('id')->sharedLock()->get(['id']);
        if ($norms->count() !== count($normIds) || $versions->count() !== count($versionIds)
            || $prices->count() !== count($priceIds) || $parsed->count() !== $datasets->count()) {
            $this->rejectNormative();
        }
    }

    private function rejectNormative(): never
    {
        throw ValidationException::withMessages(['price' => trans_message('estimate_evaluation.normative_publication_source_changed')]);
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
