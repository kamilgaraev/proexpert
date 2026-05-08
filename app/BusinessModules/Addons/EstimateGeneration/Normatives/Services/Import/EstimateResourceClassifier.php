<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;

class EstimateResourceClassifier
{
    public function classify(?string $code = null, ?string $name = null, ?string $declaredType = null): string
    {
        $declared = mb_strtolower(trim((string) $declaredType));

        if ($declared !== '') {
            $normalized = $this->fromDeclaredType($declared);

            if ($normalized !== EstimateResourceType::OTHER->value) {
                return $normalized;
            }
        }

        $code = trim((string) $code);
        $name = mb_strtolower(trim((string) $name));

        if ($this->isSummaryCode($code)) {
            return EstimateResourceType::SUMMARY->value;
        }

        if ($this->isMachineLaborCode($code) || $this->looksLikeMachineLaborName($name)) {
            return EstimateResourceType::MACHINE_LABOR->value;
        }

        if ($this->isLaborCode($code) || $this->looksLikeLaborName($name)) {
            return EstimateResourceType::LABOR->value;
        }

        if ($this->isMachineCode($code) || $this->looksLikeMachineName($name)) {
            return EstimateResourceType::MACHINE->value;
        }

        if ($this->looksLikeEquipmentName($name)) {
            return EstimateResourceType::EQUIPMENT->value;
        }

        if ($this->isMaterialCode($code)) {
            return EstimateResourceType::MATERIAL->value;
        }

        return EstimateResourceType::OTHER->value;
    }

    private function fromDeclaredType(string $type): string
    {
        return match (true) {
            str_contains($type, 'machine_labor') || str_contains($type, 'machinist') => EstimateResourceType::MACHINE_LABOR->value,
            str_contains($type, 'маш') || str_contains($type, 'механ') || $type === 'machine' => EstimateResourceType::MACHINE->value,
            str_contains($type, 'обор') || $type === 'equipment' => EstimateResourceType::EQUIPMENT->value,
            str_contains($type, 'труд') || str_contains($type, 'рабоч') || str_contains($type, 'инженер') || $type === 'labor' => EstimateResourceType::LABOR->value,
            str_contains($type, 'мат') || $type === 'material' => EstimateResourceType::MATERIAL->value,
            str_contains($type, 'summary') || str_contains($type, 'group') || str_contains($type, 'итог') || str_contains($type, 'груп') => EstimateResourceType::SUMMARY->value,
            default => EstimateResourceType::OTHER->value,
        };
    }

    private function isSummaryCode(string $code): bool
    {
        return in_array($code, ['1', '2'], true);
    }

    private function isLaborCode(string $code): bool
    {
        return preg_match('/^(?:1-100-\d+|2-100-\d+|3-\d{3}-\d+)$/', $code) === 1;
    }

    private function isMachineLaborCode(string $code): bool
    {
        return preg_match('/^4-100-\d+$/', $code) === 1;
    }

    private function isMachineCode(string $code): bool
    {
        return preg_match('/^9[0-9]\./', $code) === 1;
    }

    private function isMaterialCode(string $code): bool
    {
        return preg_match('/^(?:0[1-9]|[1-8][0-9])\./', $code) === 1;
    }

    private function looksLikeLaborName(string $name): bool
    {
        return str_contains($name, 'средний разряд')
            || str_contains($name, 'рабочий')
            || str_contains($name, 'инженер')
            || str_contains($name, 'техник')
            || str_contains($name, 'чел.-ч');
    }

    private function looksLikeMachineLaborName(string $name): bool
    {
        return str_contains($name, 'машинист')
            || str_contains($name, 'отм')
            || str_contains($name, 'зтм');
    }

    private function looksLikeMachineName(string $name): bool
    {
        return str_contains($name, 'маш.-ч')
            || str_contains($name, 'экскаватор')
            || str_contains($name, 'бульдозер')
            || str_contains($name, 'кран ')
            || str_contains($name, 'автомобил')
            || str_contains($name, 'компрессор');
    }

    private function looksLikeEquipmentName(string $name): bool
    {
        return str_contains($name, 'оборудование')
            || str_contains($name, 'шкаф ')
            || str_contains($name, 'щит ')
            || str_contains($name, 'станция ')
            || str_contains($name, 'агрегат ');
    }
}
