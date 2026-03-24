<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class EstimateDecompositionService
{
    public function decompose(array $analysis): array
    {
        $localEstimates = [];

        foreach ($analysis['detected_structure']['scopes'] ?? [] as $index => $scope) {
            $localEstimates[] = [
                'key' => 'local-estimate-' . ($index + 1),
                'title' => $scope['title'],
                'scope_type' => $scope['scope_type'] ?? 'custom',
                'source_refs' => $this->normalizeSourceRefs($scope['source_refs'] ?? []),
                'assumptions' => $this->buildAssumptions($analysis, $scope),
                'sections' => [
                    [
                        'key' => 'section-' . ($index + 1) . '-1',
                        'title' => $scope['title'],
                        'construction_part' => $scope['scope_type'] ?? 'custom',
                        'source_refs' => $this->normalizeSourceRefs($scope['source_refs'] ?? []),
                    ],
                ],
            ];
        }

        return $localEstimates;
    }

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
