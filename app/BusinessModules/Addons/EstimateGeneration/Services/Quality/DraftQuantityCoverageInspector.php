<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;

final class DraftQuantityCoverageInspector
{
    private const BLOCKING_REASONS = [
        'heating_source_type_missing',
        'sewer_outlet_route_missing',
    ];

    /**
     * @param  array<string, mixed>  $draft
     * @return array{
     *     blocking: list<array{quantity_key: string, reason: string, package_key: string, message?: string}>,
     *     advisory: list<array{quantity_key: string, reason: string, package_key: string, message?: string}>
     * }
     */
    public function inspect(array $draft): array
    {
        $blocking = [];
        $advisory = [];
        $seen = [];

        foreach ((array) ($draft['local_estimates'] ?? []) as $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            $packageKey = trim((string) ($localEstimate['key'] ?? ''));
            foreach ((array) ($localEstimate['coverage_warnings'] ?? []) as $warning) {
                if (! QuantityCoverageWarning::isValid($warning)
                    || trim((string) ($warning['package_key'] ?? '')) !== $packageKey) {
                    continue;
                }

                $normalized = [
                    'quantity_key' => (string) $warning['quantity_key'],
                    'reason' => (string) $warning['reason'],
                    'package_key' => (string) $warning['package_key'],
                ];
                $identity = implode('|', $normalized);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;

                $message = trim((string) ($warning['message'] ?? ''));
                if ($message !== '') {
                    $normalized['message'] = $message;
                }

                if (in_array($normalized['reason'], self::BLOCKING_REASONS, true)) {
                    $blocking[] = $normalized;
                } else {
                    $advisory[] = $normalized;
                }
            }
        }

        return ['blocking' => $blocking, 'advisory' => $advisory];
    }
}
