<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\Models\SystemAdmin;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierPriceSnapshotReader
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization, private SupplierSnapshotPrice $prices = new SupplierSnapshotPrice) {}

    public function read(User|SystemAdmin $actor, EstimateGenerationSession $session, string $reference, DateTimeImmutable $businessDate, array $target = []): ?array
    {
        $this->authorization->authorize($actor, $session, 'procurement.supplier_proposals.view');
        $organizationId = (int) $session->organization_id;
        if ($organizationId < 1 || preg_match('/\Asupplier:([1-9][0-9]*):([1-9][0-9]*)\z/', $reference, $ids) !== 1) {
            return null;
        }
        $version = $this->scopedVersions($session)->find((int) $ids[1]);
        if ($version === null) {
            return null;
        }
        $source = $this->prices->extract($version->commercial_snapshot, (int) $ids[2], $reference, $businessDate, $target);

        return $source === null ? null : [...$source, 'organization_id' => $organizationId, 'source_version_id' => (int) $version->id];
    }

    /** Discovery is scoped exactly like the selected-source read, including the current proposal version. */
    public function list(User $actor, EstimateGenerationSession $session, array $item, string $search, int $page, int $perPage, ?string $targetRegion): array
    {
        $this->authorization->authorize($actor, $session, 'procurement.supplier_proposals.view');
        $versions = $this->scopedVersions($session);
        if ((clone $versions)->whereRaw("CASE WHEN jsonb_typeof(commercial_snapshot->'lines') = 'array' THEN jsonb_array_length(commercial_snapshot->'lines') > ? ELSE false END", [SupplierSnapshotPrice::MAX_LINES])->exists()) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_evaluation.supplier_snapshot_requires_split')]);
        }
        $lines = (clone $versions)->crossJoin(DB::raw("LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(supplier_proposal_versions.commercial_snapshot->'lines') = 'array' THEN supplier_proposal_versions.commercial_snapshot->'lines' ELSE '[]'::jsonb END) WITH ORDINALITY AS offered_line(value, ordinal)"));
        if ($search !== '') {
            $lines->whereRaw("offered_line.value->>'name' ILIKE ?", ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%']);
        }
        $records = $lines->orderBy('supplier_proposal_versions.id')->orderBy('offered_line.ordinal')
            ->paginate($perPage, ['supplier_proposal_versions.id', DB::raw('offered_line.value AS offered_line')], 'page', $page);
        $headers = $this->scopedVersions($session)->whereIn('id', collect($records->items())->pluck('id')->unique()->all())->get()->keyBy('id');
        $references = [];
        foreach ($records->items() as $record) {
            $line = json_decode((string) $record->getRawOriginal('offered_line'), true, 512, JSON_THROW_ON_ERROR);
            $lineId = is_array($line) ? (int) ($line['id'] ?? 0) : 0;
            if ($lineId > 0) {
                $references[$record->id][$lineId] = 'supplier:'.$record->id.':'.$lineId;
            }
        }
        $parsed = [];
        foreach ($headers as $versionId => $header) {
            $parsed[$versionId] = $this->prices->extractMany($header->commercial_snapshot, $references[$versionId] ?? [], new DateTimeImmutable('today'),
                ['material_id' => $item['material_id'] ?? null, 'quantity' => $item['quantity'] ?? null, 'unit' => $item['unit'] ?? null]);
        }
        $prices = [];
        foreach ($records->items() as $record) {
            $line = json_decode((string) $record->getRawOriginal('offered_line'), true, 512, JSON_THROW_ON_ERROR);
            $lineId = is_array($line) ? (int) ($line['id'] ?? 0) : 0;
            $reference = 'supplier:'.$record->id.':'.max(1, $lineId);
            $price = $parsed[$record->id][$lineId] ?? null;
            if ($price === null) {
                $prices[] = ['source_reference' => $reference, 'source_hash' => null, 'name' => is_string($line['name'] ?? null) ? $line['name'] : trans_message('estimate_evaluation.supplier_price_unavailable'),
                    'unit_price' => null, 'compatible' => false, 'verified' => false, 'unavailable_reason' => 'supplier_snapshot_invalid'];

                continue;
            }
            $converted = (new ConvertEvaluationPriceUnit)->convert($price, $item['unit'] ?? null);
            $prices[] = [...($converted ?? $price), 'compatible' => $converted !== null,
                'confirmable_conditions' => (new ConfirmedCommercialPriceConditions)->required($price, $targetRegion),
                'organization_id' => (int) $session->organization_id, 'source_version_id' => (int) $record->id];
        }

        return ['sources' => $prices, 'state_version' => (int) $session->state_version, 'work_item_key' => $item['key'], 'target_region' => $targetRegion,
            'pagination' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $perPage, 'total' => $records->total()], 'cost_units' => '0'];
    }

    private function scopedVersions(EstimateGenerationSession $session): Builder
    {
        $organizationId = (int) $session->organization_id;

        return SupplierProposalVersion::query()->where('supplier_proposal_versions.organization_id', $organizationId)->where('integrity_status', 'verified')
            ->whereHas('supplierProposal', static fn ($query) => $query->where('organization_id', $organizationId)->whereIn('status', ['submitted', 'accepted'])
                ->whereHas('supplierRequest', static fn ($request) => $request->where('organization_id', $organizationId)
                    ->whereHas('purchaseRequest', static fn ($purchase) => $purchase->where('organization_id', $organizationId)
                        ->whereHas('siteRequest', static fn ($site) => $site->where('organization_id', $organizationId)->where('project_id', $session->project_id)))))
            ->whereNotExists(static fn ($newer) => $newer->selectRaw('1')->from('supplier_proposal_versions as newer')
                ->whereColumn('newer.supplier_proposal_id', 'supplier_proposal_versions.supplier_proposal_id')
                ->whereColumn('newer.organization_id', 'supplier_proposal_versions.organization_id')
                ->whereColumn('newer.version_number', '>', 'supplier_proposal_versions.version_number'));
    }
}
