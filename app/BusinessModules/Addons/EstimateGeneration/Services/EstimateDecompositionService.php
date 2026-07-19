<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\PackagePlanData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;
use Throwable;

use function trans_message;

class EstimateDecompositionService
{
    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    public function decompose(array $analysis): array
    {
        $localEstimates = [];

        foreach ($analysis['detected_structure']['scopes'] ?? [] as $scope) {
            $scopeType = (string) ($scope['scope_type'] ?? 'custom');

            $localEstimates[] = [
                'key' => 'local-'.$scopeType,
                'title' => $scope['title'],
                'scope_type' => $scopeType,
                'source_refs' => $this->normalizeSourceRefs($scope['source_refs'] ?? []),
                'assumptions' => $this->buildAssumptions($analysis, $scope),
                'sections' => [
                    [
                        'key' => 'section-'.$scopeType.'-1',
                        'title' => $scope['title'],
                        'construction_part' => $scopeType,
                        'source_refs' => $this->normalizeSourceRefs($scope['source_refs'] ?? []),
                    ],
                ],
            ];
        }

        return $localEstimates;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    public function decomposePackagePlan(array $analysis, PackagePlanData $plan): array
    {
        return array_map(function (array $package) use ($analysis): array {
            $sourceRefs = $package['source_refs'] ?? [];

            if ($sourceRefs === []) {
                $sourceRefs = $this->documentSourceRefs($analysis);
            }

            return [
                'key' => $package['key'],
                'title' => $package['title'],
                'scope_type' => $package['scope_type'],
                'target_items_min' => $package['target_items_min'],
                'target_items_max' => $package['target_items_max'],
                'source_refs' => $sourceRefs,
                'assumptions' => $this->buildAssumptions($analysis, [
                    'package_key' => $package['key'],
                    'source_refs' => $sourceRefs,
                ]),
                'coverage_warnings' => $this->coverageWarnings($analysis, (string) $package['key']),
                'sections' => [
                    [
                        'key' => $package['key'].'-section-1',
                        'title' => $package['title'],
                        'construction_part' => $package['scope_type'],
                        'source_refs' => $sourceRefs,
                    ],
                ],
            ];
        }, $plan->packages);
    }

    /** @return list<array<string, mixed>> */
    private function coverageWarnings(array $analysis, string $packageKey): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $warnings = is_array($documentContext['quantity_coverage_warnings'] ?? null)
            ? $documentContext['quantity_coverage_warnings']
            : [];

        $packageWarnings = array_values(array_filter(
            $warnings,
            static fn (mixed $warning): bool => QuantityCoverageWarning::isValid($warning)
                && trim((string) ($warning['package_key'] ?? '')) === $packageKey,
        ));

        $presentedWarnings = array_map(function (array $warning): array {
            $reason = trim((string) ($warning['reason'] ?? ''));
            $message = $reason !== '' ? $this->quantityCoverageWarningMessage($reason) : null;

            return $message === null ? $warning : [...$warning, 'message' => $message];
        }, $packageWarnings);

        $seenReasons = [];

        return array_values(array_filter($presentedWarnings, static function (array $warning) use (&$seenReasons): bool {
            $reason = trim((string) ($warning['reason'] ?? ''));
            if ($reason === '' || ! isset($seenReasons[$reason])) {
                $seenReasons[$reason] = true;

                return true;
            }

            return false;
        }));
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $scope
     * @return array<int, string>
     */
    protected function buildAssumptions(array $analysis, array $scope): array
    {
        $assumptions = [];
        $area = $analysis['object']['area'] ?? null;

        if ($area) {
            $assumptions[] = 'Расчеты частично опираются на площадь объекта '.$area.' м2';
        }

        if (($scope['source_refs']['sheets'] ?? []) === []) {
            $assumptions[] = 'Для блока не найден явный лист, использовано текстовое описание';
        }

        $packageKey = trim((string) ($scope['package_key'] ?? ''));
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $coverageWarnings = is_array($documentContext['quantity_coverage_warnings'] ?? null)
            ? $documentContext['quantity_coverage_warnings']
            : [];
        foreach ($coverageWarnings as $warning) {
            if (! is_array($warning) || trim((string) ($warning['package_key'] ?? '')) !== $packageKey) {
                continue;
            }

            $reason = trim((string) ($warning['reason'] ?? ''));
            if ($reason === '') {
                continue;
            }

            $message = $this->quantityCoverageWarningMessage($reason);
            if ($message !== null) {
                $assumptions[] = $message;
            }
        }

        return array_values(array_unique($assumptions));
    }

    private function quantityCoverageWarningMessage(string $reason): ?string
    {
        $fallback = match ($reason) {
            'stair_construction_geometry_missing' => 'Лестничные марши и площадки не включены: в документах нет конструкции, размеров и объёмов лестницы.',
            'stair_railing_geometry_missing' => 'Лестничные ограждения не включены: в документах нет длины, материала и конструкции ограждений.',
            'grounding_installation_type_missing' => 'Контур заземления не включён: в документах не указан тип и схема устройства заземления.',
            default => null,
        };
        try {
            if (function_exists('app') && app()->bound('translator')) {
                return trans_message('estimate_generation.quantity_coverage_warnings.'.$reason);
            }
        } catch (Throwable) {
            return $fallback;
        }

        return $fallback;
    }

    /**
     * @param  array<string, array<int, string>>  $sourceRefs
     * @return array<int, array{type: string, value: string}>
     */
    protected function normalizeSourceRefs(array $sourceRefs): array
    {
        $refs = [];

        foreach ($sourceRefs['sheets'] ?? [] as $sheet) {
            $refs[] = ['type' => 'sheet', 'value' => $sheet];
        }

        foreach ($sourceRefs['elevations'] ?? [] as $elevation) {
            $refs[] = ['type' => 'elevation', 'value' => $elevation];
        }

        foreach ($sourceRefs['floors'] ?? [] as $floor) {
            $refs[] = ['type' => 'floor', 'value' => $floor];
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    private function documentSourceRefs(array $analysis): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $sourceRefs = is_array($documentContext['source_refs'] ?? null) ? $documentContext['source_refs'] : [];

        return array_values(array_filter($sourceRefs, 'is_array'));
    }
}
