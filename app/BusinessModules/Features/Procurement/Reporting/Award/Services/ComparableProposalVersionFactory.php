<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ComparableProposalVersion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final readonly class ComparableProposalVersionFactory
{
    public function make(SupplierProposalVersion $version): ComparableProposalVersion
    {
        $snapshot = $version->commercial_snapshot;
        $lines = is_array($snapshot['lines'] ?? null) ? array_values($snapshot['lines']) : [];
        if ($lines === []
            || $version->supplier_party_id === null
            || ! is_string($snapshot['total_amount'] ?? null)
            || ! is_string($snapshot['delivery_amount'] ?? null)
            || ! is_string($snapshot['currency'] ?? null)
            || ! is_string($snapshot['vat_mode'] ?? null)
            || (isset($snapshot['vat_rate']) && ! is_string($snapshot['vat_rate']))) {
            throw new DomainException('Proposal version lacks an exact comparison basis.');
        }

        $basisLines = [];
        foreach ($lines as $line) {
            if (! is_array($line)
                || ! is_string($line['quantity'] ?? null)
                || ! is_string($line['unit'] ?? null)
                || trim($line['unit']) === '') {
                throw new DomainException('Proposal version line lacks an exact quantity and unit basis.');
            }
            $basisLines[] = [
                'supplier_request_line_id' => $line['supplier_request_line_id'] ?? null,
                'material_id' => $line['material_id'] ?? null,
                'name' => trim((string) ($line['name'] ?? '')),
                'specification' => trim((string) ($line['specification'] ?? '')),
                'quantity' => (string) BigDecimal::of((string) $line['quantity'])->strippedOfTrailingZeros(),
                'unit' => mb_strtolower(trim((string) $line['unit'])),
            ];
        }
        usort(
            $basisLines,
            static fn (array $left, array $right): int => CanonicalJson::encode($left)
                <=> CanonicalJson::encode($right),
        );
        $quantityBasis = array_map(
            static fn (array $line): array => [
                'supplier_request_line_id' => $line['supplier_request_line_id'],
                'quantity' => $line['quantity'],
            ],
            $basisLines,
        );
        $unitBasis = array_map(
            static fn (array $line): array => [
                'supplier_request_line_id' => $line['supplier_request_line_id'],
                'unit' => $line['unit'],
            ],
            $basisLines,
        );
        $amount = BigDecimal::of((string) $snapshot['total_amount'])
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
        $unitHash = hash('sha256', CanonicalJson::encode($unitBasis));

        return new ComparableProposalVersion(
            proposalVersionId: (int) $version->getKey(),
            supplierId: (int) $version->supplier_party_id,
            amountMinor: $amount,
            currency: (string) $snapshot['currency'],
            materialSpecificationHash: hash('sha256', CanonicalJson::encode($basisLines)),
            quantity: hash('sha256', CanonicalJson::encode($quantityBasis)),
            unit: $unitHash,
            vatBasis: (string) $snapshot['vat_mode'].':'.(string) ($snapshot['vat_rate'] ?? ''),
            freightBasis: (string) ($snapshot['delivery_terms'] ?? '').':'
                .(string) ($snapshot['delivery_amount'] ?? ''),
            unitDimension: 'proposal-unit-set:'.$unitHash,
            conversionVersion: 'proposal-line-identity-v2',
        );
    }
}
