<?php

namespace App\BusinessModules\Features\SiteRequests\Enums;

/**
 * Типы событий календаря для заявок
 */
enum CalendarEventTypeEnum: string
{
    case MATERIAL_DELIVERY = 'material_delivery';
    case PERSONNEL_WORK = 'personnel_work';
    case EQUIPMENT_RENTAL = 'equipment_rental';
    case DEADLINE = 'deadline';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::MATERIAL_DELIVERY => 'Доставка материалов',
            self::PERSONNEL_WORK => 'Работа персонала',
            self::EQUIPMENT_RENTAL => 'Аренда техники',
            self::DEADLINE => 'Дедлайн заявки',
        };
    }

    /**
     * Получить цвет события по умолчанию
     */
    public function color(): string
    {
        return match($this) {
            self::MATERIAL_DELIVERY => '#4CAF50',
            self::PERSONNEL_WORK => '#2196F3',
            self::EQUIPMENT_RENTAL => '#FF9800',
            self::DEADLINE => '#F44336',
        };
    }

    /**
     * Получить иконку события
     */
    public function icon(): string
    {
        return match($this) {
            self::MATERIAL_DELIVERY => 'truck',
            self::PERSONNEL_WORK => 'users',
            self::EQUIPMENT_RENTAL => 'cogs',
            self::DEADLINE => 'clock',
        };
    }

    /**
     * Определить тип события на основе типа заявки
     */
    public static function fromRequestType(SiteRequestTypeEnum $requestType): self
    {
        return match($requestType) {
            SiteRequestTypeEnum::MATERIAL_REQUEST => self::MATERIAL_DELIVERY,
            SiteRequestTypeEnum::PERSONNEL_REQUEST => self::PERSONNEL_WORK,
            SiteRequestTypeEnum::EQUIPMENT_REQUEST => self::EQUIPMENT_RENTAL,
            default => self::DEADLINE,
        };
    }

    /**
     * Получить все типы как массив для валидации
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Получить все типы с метками для выбора
     */
    public static function options(): array
    {
        return array_map(
            fn(self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'icon' => $case->icon(),
            ],
            self::cases()
        );
    }
}

