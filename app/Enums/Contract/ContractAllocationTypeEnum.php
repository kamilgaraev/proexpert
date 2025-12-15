<?php

namespace App\Enums\Contract;

enum ContractAllocationTypeEnum: string
{
    /**
     * Фиксированная сумма - явно указанная сумма для проекта
     */
    case FIXED = 'fixed';
    
    /**
     * Процентное распределение - процент от общей суммы контракта
     */
    case PERCENTAGE = 'percentage';
    
    /**
     * Автоматическое распределение - на основе актов или равномерно
     */
    case AUTO = 'auto';
    
    /**
     * Пользовательская формула - кастомная логика расчета
     */
    case CUSTOM = 'custom';

    /**
     * Получить человекочитаемое название типа
     */
    public function label(): string
    {
        return match($this) {
            self::FIXED => 'Фиксированная сумма',
            self::PERCENTAGE => 'Процентное распределение',
            self::AUTO => 'Автоматическое',
            self::CUSTOM => 'Пользовательская формула',
        };
    }

    /**
     * Получить описание типа
     */
    public function description(): string
    {
        return match($this) {
            self::FIXED => 'Точная сумма, закрепленная за проектом',
            self::PERCENTAGE => 'Процент от общей суммы контракта',
            self::AUTO => 'Автоматический расчет на основе актов или равномерное распределение',
            self::CUSTOM => 'Расчет по пользовательской формуле',
        };
    }
}

