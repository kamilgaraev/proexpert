<?php

namespace App\BusinessModules\Features\SiteRequests\Enums;

/**
 * Типы персонала для заявок
 */
enum PersonnelTypeEnum: string
{
    case GENERAL_WORKER = 'general_worker';
    case SKILLED_WORKER = 'skilled_worker';
    case FOREMAN = 'foreman';
    case ENGINEER = 'engineer';
    case SPECIALIST = 'specialist';
    case OPERATOR = 'operator';
    case ELECTRICIAN = 'electrician';
    case PLUMBER = 'plumber';
    case WELDER = 'welder';
    case CARPENTER = 'carpenter';
    case MASON = 'mason';
    case PAINTER = 'painter';
    case ROOFER = 'roofer';
    case PLASTERER = 'plasterer';
    case TILER = 'tiler';
    case SECURITY = 'security';
    case DRIVER = 'driver';
    case CLEANER = 'cleaner';
    case OTHER = 'other';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::GENERAL_WORKER => 'Разнорабочий',
            self::SKILLED_WORKER => 'Квалифицированный рабочий',
            self::FOREMAN => 'Прораб',
            self::ENGINEER => 'Инженер',
            self::SPECIALIST => 'Специалист',
            self::OPERATOR => 'Оператор техники',
            self::ELECTRICIAN => 'Электрик',
            self::PLUMBER => 'Сантехник',
            self::WELDER => 'Сварщик',
            self::CARPENTER => 'Плотник',
            self::MASON => 'Каменщик',
            self::PAINTER => 'Маляр',
            self::ROOFER => 'Кровельщик',
            self::PLASTERER => 'Штукатур',
            self::TILER => 'Плиточник',
            self::SECURITY => 'Охрана',
            self::DRIVER => 'Водитель',
            self::CLEANER => 'Уборщик',
            self::OTHER => 'Другое',
        };
    }

    /**
     * Получить иконку типа персонала
     */
    public function icon(): string
    {
        return match($this) {
            self::GENERAL_WORKER => 'hard-hat',
            self::SKILLED_WORKER => 'user-cog',
            self::FOREMAN => 'user-tie',
            self::ENGINEER => 'drafting-compass',
            self::SPECIALIST => 'user-graduate',
            self::OPERATOR => 'cogs',
            self::ELECTRICIAN => 'bolt',
            self::PLUMBER => 'faucet',
            self::WELDER => 'fire',
            self::CARPENTER => 'hammer',
            self::MASON => 'cubes',
            self::PAINTER => 'paint-roller',
            self::ROOFER => 'home',
            self::PLASTERER => 'trowel',
            self::TILER => 'th',
            self::SECURITY => 'shield-alt',
            self::DRIVER => 'car',
            self::CLEANER => 'broom',
            self::OTHER => 'user',
        };
    }

    /**
     * Получить категорию персонала
     */
    public function category(): string
    {
        return match($this) {
            self::GENERAL_WORKER, self::CLEANER => 'general',
            self::SKILLED_WORKER, self::MASON, self::CARPENTER, self::PLASTERER, self::TILER, self::PAINTER, self::ROOFER => 'construction',
            self::ELECTRICIAN, self::PLUMBER, self::WELDER => 'engineering',
            self::FOREMAN, self::ENGINEER, self::SPECIALIST => 'management',
            self::OPERATOR, self::DRIVER => 'machinery',
            self::SECURITY => 'security',
            self::OTHER => 'other',
        };
    }

    /**
     * Получить среднюю почасовую ставку (базовую)
     */
    public function baseHourlyRate(): int
    {
        return match($this) {
            self::GENERAL_WORKER => 250,
            self::SKILLED_WORKER => 400,
            self::FOREMAN => 600,
            self::ENGINEER => 700,
            self::SPECIALIST => 550,
            self::OPERATOR => 500,
            self::ELECTRICIAN => 450,
            self::PLUMBER => 450,
            self::WELDER => 500,
            self::CARPENTER => 400,
            self::MASON => 450,
            self::PAINTER => 350,
            self::ROOFER => 450,
            self::PLASTERER => 400,
            self::TILER => 450,
            self::SECURITY => 200,
            self::DRIVER => 350,
            self::CLEANER => 200,
            self::OTHER => 300,
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

