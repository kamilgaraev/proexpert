<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection;

/**
 * Интерфейс для детекторов типов смет
 * 
 * Каждый конкретный детектор определяет один тип сметы
 * (ГрандСмета, РИК, ФЕР, SmartSmeta, произвольная таблица)
 */
interface EstimateTypeDetectorInterface
{
    /**
     * Определить тип сметы по содержимому файла
     * 
     * @param mixed $content Содержимое файла (Worksheet для Excel, SimpleXMLElement для XML, массив для CSV)
     * @return array ['confidence' => float, 'indicators' => array]
     */
    public function detect($content): array;
    
    /**
     * Получить тип сметы, который определяет этот детектор
     * 
     * @return string Тип сметы: 'grandsmeta', 'rik', 'fer', 'smartsmeta', 'custom'
     */
    public function getType(): string;
    
    /**
     * Получить описание типа сметы
     * 
     * @return string Человекочитаемое описание
     */
    public function getDescription(): string;
}

