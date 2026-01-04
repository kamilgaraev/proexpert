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
        // Улучшенная логика: проверяем суффикс (м - монтаж, р - ремонт) и контекст имени
        if (preg_match('/^(ГЭСН|ГСН|ФЕР|ТЕР)([мрп])?/ui', $code, $matches)) {
            $suffix = mb_strtolower($matches[2] ?? '');
            
            // Если это монтажный сборник ('м'), но название не начинается с явного действия (Монтаж...),
            // то это может быть оборудование/материал (Светильник, Кабель).
            // Отдаем AI на перепроверку (confidence < 0.8).
            if ($suffix === 'м') {
                 $nameLower = mb_strtolower($name);
                 $isActivity = false;
                 // Список слов, указывающих на работу
                 $activities = [
                     'монтаж', 'установка', 'укладка', 'устройство', 'разборка', 
                     'смена', 'демонтаж', 'прокладка', 'врезка', 'заделка', 
                     'окраска', 'изоляция', 'присоединение', 'сборка', 'настройка'
                 ];
                 
                 foreach ($activities as $act) {
                     if (str_starts_with($nameLower, $act)) {
                         $isActivity = true;
                         break;
                     }
                 }
                 
                 if (!$isActivity) {
                     // Возвращаем work, но с низкой уверенностью (0.6), чтобы AI мог переопределить в material/equipment
                     return new ClassificationResult('work', 0.6, 'regex_pattern_weak');
                 }
            }

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
