<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use DateTimeImmutable;

final readonly class ResolveCommercialWorkPrice
{
    public function __construct(private CataloguePriceSnapshotReader $catalog, private SupplierPriceSnapshotReader $suppliers) {}

    public function resolve(User|SystemAdmin $actor, EstimateGenerationSession $session, array $workItem): ?array
    {
        $selection = $session->input_payload['price_selections'][$workItem['key'] ?? ''] ?? null;
        if (! is_array($selection) || ! is_string($selection['source_reference'] ?? null)
            || ! is_string($selection['source_hash'] ?? null)) {
            return null;
        }
        $reference = $selection['source_reference'];
        $price = str_starts_with($reference, 'supplier:')
            ? $this->suppliers->read($actor, $session, $reference, new DateTimeImmutable('today'), [
                'material_id' => $workItem['material_id'] ?? null, 'quantity' => $workItem['quantity'] ?? null, 'unit' => $workItem['unit'] ?? null])
            : $this->catalog->read((int) $session->organization_id, $reference);
        if ($price === null || ! hash_equals($price['source_hash'], $selection['source_hash'])) {
            return null;
        }
        $price['stale_accepted'] = ($selection['stale_accepted'] ?? false) === true;

        return $price;
    }
}
