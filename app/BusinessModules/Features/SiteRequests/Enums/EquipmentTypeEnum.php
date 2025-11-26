<?php

namespace App\BusinessModules\Features\SiteRequests\Enums;

/**
 * Типы техники для заявок
 */
enum EquipmentTypeEnum: string
{
    case TOWER_CRANE = 'tower_crane';
    case MOBILE_CRANE = 'mobile_crane';
    case EXCAVATOR = 'excavator';
    case BULLDOZER = 'bulldozer';
    case LOADER = 'loader';
    case DUMP_TRUCK = 'dump_truck';
    case CONCRETE_MIXER = 'concrete_mixer';
    case CONCRETE_PUMP = 'concrete_pump';
    case FORKLIFT = 'forklift';
    case SCAFFOLDING = 'scaffolding';
    case COMPRESSOR = 'compressor';
    case GENERATOR = 'generator';
    case WELDING_MACHINE = 'welding_machine';
    case VIBRATOR = 'vibrator';
    case GRADER = 'grader';
    case ROLLER = 'roller';
    case OTHER = 'other';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::TOWER_CRANE => 'Башенный кран',
            self::MOBILE_CRANE => 'Автокран',
            self::EXCAVATOR => 'Экскаватор',
            self::BULLDOZER => 'Бульдозер',
            self::LOADER => 'Погрузчик',
            self::DUMP_TRUCK => 'Самосвал',
            self::CONCRETE_MIXER => 'Бетономешалка',
            self::CONCRETE_PUMP => 'Бетононасос',
            self::FORKLIFT => 'Вилочный погрузчик',
            self::SCAFFOLDING => 'Строительные леса',
            self::COMPRESSOR => 'Компрессор',
            self::GENERATOR => 'Генератор',
            self::WELDING_MACHINE => 'Сварочный аппарат',
            self::VIBRATOR => 'Вибратор для бетона',
            self::GRADER => 'Грейдер',
            self::ROLLER => 'Каток',
            self::OTHER => 'Другое',
        };
    }

    /**
     * Получить иконку типа техники
     */
    public function icon(): string
    {
        return match($this) {
            self::TOWER_CRANE => 'industry',
            self::MOBILE_CRANE => 'truck-loading',
            self::EXCAVATOR => 'snowplow',
            self::BULLDOZER => 'tractor',
            self::LOADER => 'truck-moving',
            self::DUMP_TRUCK => 'truck',
            self::CONCRETE_MIXER => 'blender',
            self::CONCRETE_PUMP => 'water',
            self::FORKLIFT => 'pallet',
            self::SCAFFOLDING => 'th',
            self::COMPRESSOR => 'compress',
            self::GENERATOR => 'bolt',
            self::WELDING_MACHINE => 'fire',
            self::VIBRATOR => 'wave-square',
            self::GRADER => 'road',
            self::ROLLER => 'circle',
            self::OTHER => 'cog',
        };
    }

    /**
     * Получить категорию техники
     */
    public function category(): string
    {
        return match($this) {
            self::TOWER_CRANE, self::MOBILE_CRANE, self::FORKLIFT => 'lifting',
            self::EXCAVATOR, self::BULLDOZER, self::GRADER, self::ROLLER => 'earthwork',
            self::LOADER, self::DUMP_TRUCK => 'transport',
            self::CONCRETE_MIXER, self::CONCRETE_PUMP, self::VIBRATOR => 'concrete',
            self::SCAFFOLDING => 'scaffolding',
            self::COMPRESSOR, self::GENERATOR, self::WELDING_MACHINE => 'tools',
            self::OTHER => 'other',
        };
    }

    /**
     * Требуется ли оператор по умолчанию
     */
    public function requiresOperator(): bool
    {
        return match($this) {
            self::TOWER_CRANE, self::MOBILE_CRANE, self::EXCAVATOR, self::BULLDOZER,
            self::LOADER, self::DUMP_TRUCK, self::CONCRETE_PUMP, self::FORKLIFT,
            self::GRADER, self::ROLLER => true,
            default => false,
        };
    }

    /**
     * Получить примерную стоимость аренды в час
     */
    public function baseHourlyRate(): int
    {
        return match($this) {
            self::TOWER_CRANE => 5000,
            self::MOBILE_CRANE => 4000,
            self::EXCAVATOR => 3000,
            self::BULLDOZER => 3500,
            self::LOADER => 2500,
            self::DUMP_TRUCK => 2000,
            self::CONCRETE_MIXER => 3000,
            self::CONCRETE_PUMP => 4000,
            self::FORKLIFT => 1500,
            self::SCAFFOLDING => 500,
            self::COMPRESSOR => 800,
            self::GENERATOR => 1000,
            self::WELDING_MACHINE => 500,
            self::VIBRATOR => 300,
            self::GRADER => 3500,
            self::ROLLER => 2500,
            self::OTHER => 1000,
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
                'icon' => $case->icon(),
                'category' => $case->category(),
                'requires_operator' => $case->requiresOperator(),
                'base_hourly_rate' => $case->baseHourlyRate(),
            ],
            self::cases()
        );
    }

    /**
     * Получить типы по категории
     */
    public static function byCategory(string $category): array
    {
        return array_filter(
            self::cases(),
            fn(self $case) => $case->category() === $category
        );
    }
}

