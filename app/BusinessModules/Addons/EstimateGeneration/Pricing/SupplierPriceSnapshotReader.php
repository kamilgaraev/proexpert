<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\Models\SystemAdmin;
use App\Models\User;
use DateTimeImmutable;

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
        $version = SupplierProposalVersion::query()->where('organization_id', $organizationId)->where('integrity_status', 'verified')
            ->whereHas('supplierProposal', static fn ($query) => $query->where('organization_id', $organizationId)->whereIn('status', ['submitted', 'accepted'])
                ->whereHas('supplierRequest', static fn ($request) => $request->where('organization_id', $organizationId)
                    ->whereHas('purchaseRequest', static fn ($purchase) => $purchase->where('organization_id', $organizationId)
                        ->whereHas('siteRequest', static fn ($site) => $site->where('organization_id', $organizationId)->where('project_id', $session->project_id)))))
            ->find((int) $ids[1]);
        if ($version === null) {
            return null;
        }
        $source = $this->prices->extract($version->commercial_snapshot, (int) $ids[2], $reference, $businessDate, $target);

        return $source === null ? null : [...$source, 'organization_id' => $organizationId, 'source_version_id' => (int) $version->id];
    }
}
