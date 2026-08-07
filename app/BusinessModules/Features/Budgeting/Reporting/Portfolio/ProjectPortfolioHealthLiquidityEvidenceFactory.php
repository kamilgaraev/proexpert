<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\CurrencyCode;

final class ProjectPortfolioHealthLiquidityEvidenceFactory
{
    /** @param list<PaymentCalendarItem> $items @param list<array<string,mixed>> $versions @param list<array<string,mixed>> $gaps */
    public function make(array $items, array $versions, array $gaps, string $asOf): ProjectPortfolioHealthSourceComponent|ProjectPortfolioHealthSourceGap
    {
        if ($items === [] || $gaps !== [] || $versions === []) {
            return new ProjectPortfolioHealthSourceGap('liquidity_source_gap', 'portfolio_liquidity');
        }
        $refs = [];
        $calendar = [];
        foreach ($items as $item) {
            if (! $item instanceof PaymentCalendarItem
                || trim($item->sourceType) === ''
                || $item->sourceId === null
                || trim((string) $item->sourceId) === ''
                || CurrencyCode::tryFrom(mb_strtoupper(trim($item->currency))) === null) {
                return new ProjectPortfolioHealthSourceGap('liquidity_source_integrity_invalid', 'portfolio_liquidity');
            }
            $refs[$this->sourceKey($item->sourceType, $item->sourceId)] = true;
            $calendar[] = $item->toArray();
        }
        $matchedVersions = [];
        foreach ($versions as $version) {
            if (! is_array($version)) {
                return new ProjectPortfolioHealthSourceGap('liquidity_source_integrity_invalid', 'portfolio_liquidity');
            }
            $sourceType = trim((string) ($version['source_type'] ?? ''));
            $sourceId = $version['source_id'] ?? null;
            if ($sourceType === ''
                || (! is_int($sourceId) && ! is_string($sourceId))
                || trim((string) $sourceId) === '') {
                return new ProjectPortfolioHealthSourceGap('liquidity_source_integrity_invalid', 'portfolio_liquidity');
            }
            $key = $this->sourceKey($sourceType, $sourceId);
            if (! isset($refs[$key])) {
                continue;
            }
            if (isset($matchedVersions[$key])
                || ($version['history_complete'] ?? false) !== true
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($version['source_hash'] ?? '')) !== 1
                || trim((string) ($version['source_version'] ?? '')) === '') {
                return new ProjectPortfolioHealthSourceGap('liquidity_source_integrity_invalid', 'portfolio_liquidity');
            }
            $matchedVersions[$key] = $version;
        }
        if (count($matchedVersions) !== count($refs)) {
            return new ProjectPortfolioHealthSourceGap('liquidity_source_gap', 'portfolio_liquidity');
        }
        usort($calendar, static fn (array $left, array $right): int => strcmp(
            CanonicalJson::encode($left),
            CanonicalJson::encode($right),
        ));
        $matchedVersions = array_values($matchedVersions);
        usort($matchedVersions, static fn (array $left, array $right): int => strcmp(
            CanonicalJson::encode($left),
            CanonicalJson::encode($right),
        ));
        $hash = hash('sha256', CanonicalJson::encode(['calendar' => $calendar, 'versions' => $matchedVersions]));

        return new ProjectPortfolioHealthSourceComponent('portfolio_liquidity', 'liquidity:'.substr($hash, 0, 24), $hash, 'portfolio_liquidity_sources_v1', $asOf);
    }

    private function sourceKey(string $sourceType, int|string $sourceId): string
    {
        return trim($sourceType).':'.trim((string) $sourceId);
    }
}
