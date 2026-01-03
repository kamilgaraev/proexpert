<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies;

use App\BusinessModules\Features\BudgetEstimates\Contracts\ClassificationStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;

class RegexStrategy implements ClassificationStrategyInterface
{
    public function classify(string $code, string $name, ?string $unit = null, ?float $price = null): ?ClassificationResult
    {
        if (empty($code)) {
            return null;
        }

        $code = trim($code);

        // ⭐ ТРУДОЗАТРАТЫ: Техническая часть ГЭСН (1-100-20, 4-100-060)
        if (preg_match('/^\d-\d{3}-\d{2,3}$/u', $code)) {
            return new ClassificationResult('labor', 1.0, 'regex_strict');
        }

        // ⭐ МАТЕРИАЛЫ: СЦМ (Сборники цен на материалы)
        if (preg_match('/^СЦМ-\d{3,4}-\d{3,4}$/ui', $code)) {
            return new ClassificationResult('material', 1.0, 'regex_strict');
        }

        // ⭐ РАБОТЫ: ГЭСН, ФЕР, ТЕР
        if (preg_match('/^(ГЭСН|ГСН|ФЕР|ТЕР)/ui', $code)) {
            return new ClassificationResult('work', 1.0, 'regex_strict');
        }

        // РАБОТЫ: формат XX-XX-XXX-XX
        if (preg_match('/^\d{2}-\d{2}-\d{3}-\d{1,2}$/u', $code)) {
            return new ClassificationResult('work', 0.9, 'regex_pattern');
        }

        // РАБОТЫ: ФСБЦ
        if (preg_match('/^(ФСБЦ|ФССЦ|ФСБЦс|ФССЦп)[А-Я]?-\d{2}\.\d/ui', $code)) {
            return new ClassificationResult('work', 1.0, 'regex_strict');
        }

        // МАТЕРИАЛЫ: ФСБЦ материалы (01.X.XX.XX-XXXX или 14.X.XX.XX-XXXX)
        if (preg_match('/^(01|14)\.\d{1,2}\.\d{1,2}\.\d{1,2}-\d{4}$/u', $code)) {
            return new ClassificationResult('material', 1.0, 'regex_strict');
        }

        // МЕХАНИЗМЫ/ОБОРУДОВАНИЕ: коды 91.XX.XX-XXX
        if (preg_match('/^91\.\d{2}\.\d{2}-\d{3}$/u', $code)) {
            return new ClassificationResult('equipment', 1.0, 'regex_strict');
        }

        // ОБОРУДОВАНИЕ: коды 08.X.XX.XX-XXXX
        if (preg_match('/^08\.\d{1,2}\.\d{1,2}\.\d{1,2}-\d{4}$/u', $code)) {
            return new ClassificationResult('equipment', 1.0, 'regex_strict');
        }

        // МАТЕРИАЛЫ: общий формат XX.XX.XX-XXX (кроме 91 и 08)
        if (preg_match('/^(\d{2})\.\d{2}\.\d{2}-\d{3,4}$/u', $code, $matches)) {
            $prefix = $matches[1];
            if (!in_array($prefix, ['91', '08'])) {
                return new ClassificationResult('material', 0.9, 'regex_pattern');
            }
        }
        
        // Дополнительные проверки из старого детектора
        
        // Трудозатраты
        if (preg_match('/^(ОТм|ЭТм|ТЗ|ТЗм|ТЗп|ФОТ)/ui', $code)) {
            return new ClassificationResult('labor', 1.0, 'regex_prefix');
        }
        
        // Материалы
        if (preg_match('/^(МАТ|ГЭСНм|ФЕРм|ТЕРм)/ui', $code)) {
            return new ClassificationResult('material', 1.0, 'regex_prefix');
        }
        
        // Механизмы
        if (preg_match('/^(МР|ЭМ|ГЭСНр|ФЕРр|ТЕРр)/ui', $code)) {
            return new ClassificationResult('equipment', 1.0, 'regex_prefix');
        }

        return null;
    }

    public function classifyBatch(array $items): array
    {
        $results = [];
        foreach ($items as $index => $item) {
            $code = $item['code'] ?? '';
            $name = $item['name'] ?? '';
            $unit = $item['unit'] ?? null;
            $price = $item['price'] ?? null;
            
            $result = $this->classify($code, $name, $unit, $price);
            if ($result) {
                $results[$index] = $result;
            }
        }
        return $results;
    }

    public function getName(): string
    {
        return 'regex';
    }
}
