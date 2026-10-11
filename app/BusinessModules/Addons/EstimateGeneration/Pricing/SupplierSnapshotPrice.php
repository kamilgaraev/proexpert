<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final class SupplierSnapshotPrice
{
    public function extract(array $snapshot, int $lineId, string $reference, DateTimeImmutable $businessDate, array $target = []): ?array
    {
        $line = null;
        $subtotal = BigDecimal::zero();
        foreach ($snapshot['lines'] ?? [] as $candidate) {
            if (! is_array($candidate)) {
                return null;
            }
            $quantity = $this->decimal($candidate['quantity'] ?? null);
            $price = $this->decimal($candidate['unit_price'] ?? null);
            $total = $this->decimal($candidate['total_amount'] ?? null);
            if ($quantity === null || $price === null || $total === null || ! $quantity->isGreaterThan(0)
                || ! $quantity->multipliedBy($price)->toScale(2, RoundingMode::HalfUp)->isEqualTo($total)) {
                return null;
            }
            $subtotal = $subtotal->plus($total);
            if ((int) ($candidate['id'] ?? 0) === $lineId) {
                if ($line !== null) {
                    return null;
                }
                $line = $candidate;
            }
        }
        $headerSubtotal = $this->decimal($snapshot['subtotal_amount'] ?? null);
        if ($line === null || $headerSubtotal === null || ! $subtotal->isEqualTo($headerSubtotal)) {
            return null;
        }
        $delivery = $this->decimal($snapshot['delivery_amount'] ?? null);
        $vatRate = $this->decimal($snapshot['vat_rate'] ?? null);
        $vatMode = $snapshot['vat_mode'] ?? null;
        $conditions = [];
        if (! in_array($vatMode, ['included', 'excluded', 'not_applicable'], true)) {
            $conditions[] = 'vat_mode';
        }
        if ($vatMode !== 'not_applicable' && ($vatRate === null || $vatRate->isGreaterThan(100))) {
            $conditions[] = 'vat_rate';
        }
        $amountsVerified = false;
        if ($delivery !== null && in_array($vatMode, ['included', 'excluded', 'not_applicable'], true)
            && ($vatMode === 'not_applicable' || ($vatRate !== null && ! $vatRate->isGreaterThan(100)))) {
            $base = $subtotal->plus($delivery);
            $expectedVat = match ($vatMode) {
                'excluded' => $base->multipliedBy($vatRate)->dividedBy(100, 2, RoundingMode::HalfUp),
                'included' => $base->multipliedBy($vatRate)->dividedBy($vatRate->plus(100), 2, RoundingMode::HalfUp),
                default => BigDecimal::zero(),
            };
            $expectedTotal = $vatMode === 'excluded' ? $base->plus($expectedVat) : $base;
            $vat = $this->decimal($snapshot['vat_amount'] ?? null);
            $total = $this->decimal($snapshot['total_amount'] ?? null);
            if ($vat === null || $total === null || ! $vat->isEqualTo($expectedVat) || ! $total->isEqualTo($expectedTotal)) {
                return null;
            }
            $amountsVerified = true;
        } else {
            $conditions[] = 'source_amounts_unverified';
        }
        if ($delivery === null || ! $delivery->isZero()) {
            $conditions[] = 'delivery_allocation';
        }
        $conditions[] = 'region';
        $targetQuantity = $this->decimal($target['quantity'] ?? null);
        $asOfDate = $this->date($snapshot['proposal_date'] ?? null);
        $applicable = $amountsVerified && $asOfDate !== null && $asOfDate <= $businessDate->format('Y-m-d')
            && is_int($target['material_id'] ?? null) && $target['material_id'] > 0
            && (int) ($line['material_id'] ?? 0) === $target['material_id']
            && $targetQuantity !== null && $targetQuantity->isEqualTo(BigDecimal::of($line['quantity']))
            && FactVocabulary::unit($target['unit'] ?? null) === FactVocabulary::unit($line['unit'] ?? null);
        $validUntil = $this->date($snapshot['valid_until'] ?? null);
        if (($snapshot['valid_until'] ?? null) !== null && $validUntil === null) {
            $conditions[] = 'valid_until';
        }
        $applicable = $applicable && ($validUntil === null || $asOfDate <= $validUntil);

        return ['source_type' => 'supplier', 'source_reference' => $reference, 'source_line_id' => $lineId,
            'supplier_request_line_id' => $line['supplier_request_line_id'] ?? null,
            'unit_price' => $line['unit_price'], 'unit' => FactVocabulary::unit($line['unit'] ?? null),
            'currency' => is_string($snapshot['currency'] ?? null) && preg_match('/\A[A-Z]{3}\z/', $snapshot['currency']) === 1 ? $snapshot['currency'] : null,
            'as_of_date' => $asOfDate, 'valid_until' => $validUntil,
            'region' => null, 'vat_mode' => $vatMode, 'vat_rate' => $vatRate === null ? null : (string) $vatRate,
            'delivery_amount' => $delivery === null ? null : (string) $delivery, 'delivery_terms' => $snapshot['delivery_terms'] ?? null,
            'delivery_included' => $delivery !== null && $delivery->isZero(), 'price_quantity' => $line['quantity'],
            'name' => $line['name'] ?? null, 'material_id' => $line['material_id'] ?? null,
            'verified' => true, 'applicable' => $applicable, 'stale' => $validUntil !== null && $validUntil < $businessDate->format('Y-m-d'),
            'stale_accepted' => false, 'conditions_missing' => $conditions,
            'source_hash' => hash('sha256', CanonicalPipelineJson::encode(['header' => $snapshot, 'selected_line' => $line]))];
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        if (! is_string($value) || strlen($value) > 48 || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?\z/', $value) !== 1) {
            return null;
        }

        return BigDecimal::of($value);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }
}
