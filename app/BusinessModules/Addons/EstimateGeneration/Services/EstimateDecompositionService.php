<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class EstimateDecompositionService
{
    /**
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    public function decompose(array $analysis): array
    {
        $localEstimates = [];

        foreach ($analysis['detected_structure']['scopes'] ?? [] as $scope) {
            $scopeType = (string) ($scope['scope_type'] ?? 'custom');

            $localEstimates[] = [
                'key' => 'local-' . $scopeType,
                'title' => $scope['title'],
                'scope_type' => $scopeType,
                'source_refs' => $this->normalizeSourceRefs($scope['source_refs'] ?? []),
                'assumptions' => $this->buildAssumptions($analysis, $scope),
                'sections' => [
                    [
                        'key' => 'section-' . $scopeType . '-1',
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
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $scope
     * @return array<int, string>
     */
    protected function buildAssumptions(array $analysis, array $scope): array
    {
        $assumptions = [];
        $area = $analysis['object']['area'] ?? null;

        if ($area) {
            $assumptions[] = 'Расчеты частично опираются на площадь объекта ' . $area . ' м2';
        }

        if (($scope['source_refs']['sheets'] ?? []) === []) {
            $assumptions[] = 'Для блока не найден явный лист, использовано текстовое описание';
        }

        return $assumptions;
    }

    /**
     * @param array<string, array<int, string>> $sourceRefs
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
}
