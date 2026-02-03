<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\Strategies;

use App\BusinessModules\Features\BudgetEstimates\Contracts\ClassificationStrategyInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;

/**
 * Стратегия классификации на основе регулярных выражений.
 * 
 * Реализует declarative approach: правила определены в массиве,
 * что облегчает поддержку и добавление новых стандартов.
 */
class RegexStrategy implements ClassificationStrategyInterface
{
    // Типы элементов
    private const TYPE_WORK = 'work';
    private const TYPE_MATERIAL = 'material';
    private const TYPE_EQUIPMENT = 'equipment';
    private const TYPE_LABOR = 'labor';

    // Уровни уверенности
    private const CONF_STRICT = 1.0;
    private const CONF_HIGH = 0.9;
    private const CONF_MEDIUM = 0.6;

    /**
     * @var array Список правил классификации.
     * Порядок важен: сверху вниз от наиболее строгих к общим.
     */
    private array $rules = [];

    public function __construct()
    {
        $this->initRules();
    }

    public function getName(): string
    {
        return 'regex_engine';
    }

    public function classify(string $code, string $name, ?string $unit = null, ?float $price = null): ?ClassificationResult
    {
        if (empty($code)) {
            return null;
        }

        $code = trim($code);

        foreach ($this->rules as $rule) {
            if (preg_match($rule['pattern'], $code, $matches)) {
                // Если есть дополнительная логика проверки (например, анализ имени)
                if (isset($rule['validator']) && is_callable($rule['validator'])) {
                    $validationResult = call_user_func($rule['validator'], $matches, $name);
                    
                    // Если валидатор вернул null/false, пропускаем правило
                    if (!$validationResult) {
                        continue;
                    }
                    
                    // Если валидатор вернул массив с переопределениями (например, понизил уверенность)
                    if (is_array($validationResult)) {
                        return new ClassificationResult(
                            $validationResult['type'] ?? $rule['type'],
                            $validationResult['confidence'] ?? $rule['confidence'],
                            $rule['source'] . '_adjusted'
                        );
                    }
                }

                return new ClassificationResult(
                    $rule['type'],
                    $rule['confidence'],
                    $rule['source']
                );
            }
        }

        return null;
    }

    public function classifyBatch(array $items): array
    {
        $results = [];
        foreach ($items as $index => $item) {
            $result = $this->classify(
                $item['code'] ?? '',
                $item['name'] ?? '',
                $item['unit'] ?? null,
                $item['price'] ?? null
            );
            
            if ($result) {
                $results[$index] = $result;
            }
        }
        return $results;
    }

    /**
     * Инициализация правил классификации.
     * Здесь сосредоточена вся бизнес-логика определения типов.
     */
    private function initRules(): void
    {
        $this->rules = [
            // ---------------------------------------------------------
            // 1. ЖЕЛЕЗОБЕТОННЫЕ ПРАВИЛА (Strict, Confidence 1.0)
            // ---------------------------------------------------------

            // Трудозатраты (Техническая часть ГЭСН: 1-100-20)
            [
                'pattern' => '/^\d-\d{3}-\d{2,3}$/u',
                'type' => self::TYPE_LABOR,
                'confidence' => self::CONF_STRICT,
                'source' => 'tech_part_labor'
            ],

            // Сборники цен на материалы (СЦМ, ФСБЦ, ФССЦ, ТЦ)
            // Исправленная логика: эти коды ВСЕГДА материалы или оборудование
            [
                'pattern' => '/^(ФСБЦ|ФССЦ|ФСБЦс|ФССЦп|ТЦ|ТССЦ|СЦ|СЦМ|Материал)[А-Я]?[-_]?\d+/ui',
                'type' => self::TYPE_MATERIAL,
                'confidence' => self::CONF_STRICT,
                'source' => 'material_price_book',
                // Уточнение: может это оборудование?
                'validator' => function ($matches, $name) {
                    if (mb_stripos($name, 'оборудование') !== false) {
                        return ['type' => self::TYPE_EQUIPMENT];
                    }
                    return true;
                }
            ],

            // ФСБЦ материалы старого/нового формата (01.X.XX.XX-XXXX или 14.X.XX.XX-XXXX)
            [
                'pattern' => '/^(01|14)\.\d{1,2}\.\d{1,2}\.\d{1,2}-\d{4}$/u',
                'type' => self::TYPE_MATERIAL,
                'confidence' => self::CONF_STRICT,
                'source' => 'fsbc_material_code'
            ],

            // Механизмы и Оборудование (91.XX.XX-XXX)
            [
                'pattern' => '/^91\.\d{2}\.\d{2}-\d{3}$/u',
                'type' => self::TYPE_EQUIPMENT, // Чаще всего это машины/механизмы, считаем equipment/machinery
                'confidence' => self::CONF_STRICT,
                'source' => 'machinery_code'
            ],

            // Оборудование (08.X.XX.XX-XXXX) - коды классификатора оборудования
            [
                'pattern' => '/^(6\d|08)\.\d{1,2}\.\d{1,2}\.\d{1,2}-\d{4}$/u',
                'type' => self::TYPE_EQUIPMENT,
                'confidence' => self::CONF_STRICT,
                'source' => 'equipment_code'
            ],

            // Оборудование явное (в коде написано "Оборудование")
            [
                'pattern' => '/^Оборудование/ui',
                'type' => self::TYPE_EQUIPMENT,
                'confidence' => self::CONF_STRICT,
                'source' => 'explicit_equipment'
            ],

            // ---------------------------------------------------------
            // 2. СТАНДАРТНЫЕ РАСЦЕНКИ (High, Confidence 0.9-1.0)
            // ---------------------------------------------------------

            // ГЭСН, ФЕР, ТЕР - основные расценки на РАБОТЫ
            [
                'pattern' => '/^(ГЭСН|ГСН|ФЕР|ТЕР)(r|м|р|п|m)?/ui',
                'type' => self::TYPE_WORK,
                'confidence' => self::CONF_STRICT,
                'source' => 'gov_standard_work',
                'validator' => function ($matches, $name) {
                    $suffix = mb_strtolower($matches[2] ?? '');
                    
                    // Если это монтажный сборник ('м'), нужно быть внимательным.
                    // Иногда под видом монтажа скрывается материал, если в названии нет действий.
                    if ($suffix === 'м') {
                        if ($this->hasActivityKeywords($name)) {
                            return true; 
                        }
                        // Нет ключевых слов действия -> понижаем уверенность, пусть решает AI
                        return ['confidence' => self::CONF_MEDIUM];
                    }
                    return true;
                }
            ],

            // Стандартный формат расценок XX-XX-XXX-XX (без префикса)
            [
                'pattern' => '/^\d{2}-\d{2}-\d{3}-\d{1,2}$/u',
                'type' => self::TYPE_WORK,
                'confidence' => self::CONF_HIGH,
                'source' => 'standard_work_format'
            ],

            // ---------------------------------------------------------
            // 3. ЭВРИСТИКА (Medium/High)
            // ---------------------------------------------------------

            // Материалы: общий формат XX.XX.XX-XXX (кроме спец. разделов выше)
            [
                'pattern' => '/^(\d{2})\.\d{2}\.\d{2}-\d{3,4}$/u',
                'type' => self::TYPE_MATERIAL,
                'confidence' => self::CONF_HIGH,
                'source' => 'generic_material_format',
                'validator' => function ($matches, $name) {
                    $prefix = $matches[1];
                    // Исключаем машины (91) и оборудование (08), они обработаны выше
                    if (in_array($prefix, ['91', '08', '61', '62', '63', '64'])) {
                        return false; // Пусть идет дальше или обрабатывается AI
                    }
                    return true;
                }
            ],
            
            // Коммерческие коды "Прайс", "Счет"
            [
                'pattern' => '/^(Прайс|Счет|Сч|ТЦ|КП)[-_]?/ui',
                'type' => self::TYPE_MATERIAL, // По умолчанию прайсы - это материалы/оборудование
                'confidence' => self::CONF_MEDIUM, // Средняя, т.к. может быть и "Услуга по..."
                'source' => 'commercial_price',
                'validator' => function ($matches, $name) {
                    if ($this->hasActivityKeywords($name)) {
                        return ['type' => self::TYPE_WORK]; // Если в прайсе "Монтаж...", то это работа
                    }
                    if (mb_stripos($name, 'оборудование') !== false) {
                        return ['type' => self::TYPE_EQUIPMENT];
                    }
                    return true;
                }
            ],
        ];
    }

    /**
     * Проверка наличия ключевых слов, обозначающих выполнение работ.
     */
    private function hasActivityKeywords(string $name): bool
    {
        $nameLower = mb_strtolower($name);
        $activities = [
            'монтаж', 'установка', 'укладка', 'устройство', 'разборка', 
            'смена', 'демонтаж', 'прокладка', 'врезка', 'заделка', 
            'окраска', 'изоляция', 'присоединение', 'сборка', 'настройка',
            'пусконалад', 'сверление', 'резка', 'очистка'
        ];
        
        foreach ($activities as $act) {
            if (str_starts_with($nameLower, $act) || str_contains($nameLower, " $act")) {
                return true;
            }
        }
        
        return false;
    }
}
