<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\EstimatePositionCatalog;
use App\Models\WorkType;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

final class CataloguePriceSnapshotReader
{
    public function read(int $organizationId, string $reference, ?DateTimeImmutable $businessDate = null): ?array
    {
        if ($organizationId < 1 || preg_match('/\A(catalog|work_type):([1-9][0-9]*)\z/', $reference, $identity) !== 1) {
            throw new InvalidArgumentException('catalog_price_reference_invalid');
        }
        $catalog = $identity[1] === 'catalog';
        $record = ($catalog ? EstimatePositionCatalog::query() : WorkType::query())
            ->where('organization_id', $organizationId)->where('is_active', true)->with('measurementUnit')->find((int) $identity[2]);
        if ($record === null || $record->measurementUnit === null
            || ($record->measurementUnit->organization_id !== null && (int) $record->measurementUnit->organization_id !== $organizationId)) {
            return null;
        }
        $metadata = $catalog ? ($record->metadata ?? []) : ($record->additional_properties ?? []);
        $amount = $catalog ? $record->unit_price : $record->default_price;
        if ($amount === null || ! BigDecimal::of((string) $amount)->isGreaterThan(0)) {
            return null;
        }
        $businessDate ??= new DateTimeImmutable('today');
        $vatRate = is_string($metadata['vat_rate'] ?? null) || is_int($metadata['vat_rate'] ?? null) ? (string) $metadata['vat_rate'] : null;
        if ($vatRate !== null && (preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', $vatRate) !== 1 || BigDecimal::of($vatRate)->isGreaterThan(100))) {
            $vatRate = null;
        }
        $source = ['organization_id' => $organizationId, 'reference' => $reference, 'name' => $record->name,
            'unit_price' => (string) $amount, 'unit' => FactVocabulary::unit($record->measurementUnit->short_name),
            'currency' => is_string($metadata['currency'] ?? null) && preg_match('/\A[A-Z]{3}\z/', strtoupper($metadata['currency'])) === 1 ? strtoupper($metadata['currency']) : null,
            'as_of_date' => $this->date($metadata['price_as_of_date'] ?? null), 'valid_until' => $this->date($metadata['price_valid_until'] ?? null),
            'region' => is_string($metadata['price_region'] ?? null) && trim($metadata['price_region']) !== '' ? $metadata['price_region'] : null,
            'vat_mode' => in_array($metadata['vat_mode'] ?? null, ['included', 'excluded', 'not_applicable'], true) ? $metadata['vat_mode'] : null,
            'vat_rate' => $vatRate,
            'delivery_included' => is_bool($metadata['delivery_included'] ?? null) ? $metadata['delivery_included'] : null,
            'updated_at' => $record->updated_at?->toISOString(),
            'item_type' => $catalog ? (string) $record->item_type : 'work'];

        $missing = array_keys(array_filter(array_intersect_key($source, array_flip(['currency', 'as_of_date', 'region', 'vat_mode', 'delivery_included'])), static fn ($value): bool => $value === null));
        if ($source['delivery_included'] === false) {
            $missing[] = 'delivery_cost';
        }
        if (in_array($source['vat_mode'], ['included', 'excluded'], true) && $source['vat_rate'] === null) {
            $missing[] = 'vat_rate';
        }
        if (($metadata['price_valid_until'] ?? null) !== null && $source['valid_until'] === null) {
            $missing[] = 'valid_until';
        }

        $businessSource = $source;
        unset($businessSource['updated_at']);

        return [...$source, 'source_type' => 'catalog', 'source_reference' => $reference, 'verified' => true,
            'applicable' => $source['as_of_date'] !== null && $source['as_of_date'] <= $businessDate->format('Y-m-d')
                && ($source['valid_until'] === null || $source['as_of_date'] <= $source['valid_until']),
            'stale' => $source['valid_until'] !== null && $source['valid_until'] < $businessDate->format('Y-m-d'), 'stale_accepted' => false,
            'source_hash' => hash('sha256', CanonicalPipelineJson::encode($businessSource)),
            'conditions_missing' => $missing,
        ];
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
