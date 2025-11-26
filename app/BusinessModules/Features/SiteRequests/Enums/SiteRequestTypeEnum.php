<?php

namespace App\BusinessModules\Features\SiteRequests\Enums;

/**
 * Типы заявок с объекта
 */
enum SiteRequestTypeEnum: string
{
    case MATERIAL_REQUEST = 'material_request';
    case PERSONNEL_REQUEST = 'personnel_request';
    case EQUIPMENT_REQUEST = 'equipment_request';
    case INFO_REQUEST = 'info_request';
    case ISSUE_REPORT = 'issue_report';
    case OTHER = 'other';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::MATERIAL_REQUEST => 'Заявка на материалы',
            self::PERSONNEL_REQUEST => 'Заявка на персонал',
            self::EQUIPMENT_REQUEST => 'Заявка на технику',
            self::INFO_REQUEST => 'Запрос информации',
            self::ISSUE_REPORT => 'Сообщение о проблеме',
            self::OTHER => 'Другое',
        };
    }

    /**
     * Получить иконку типа
     */
    public function icon(): string
    {
        return match($this) {
            self::MATERIAL_REQUEST => 'cube',
            self::PERSONNEL_REQUEST => 'users',
            self::EQUIPMENT_REQUEST => 'truck',
            self::INFO_REQUEST => 'info-circle',
            self::ISSUE_REPORT => 'exclamation-triangle',
            self::OTHER => 'question-circle',
        };
    }

    /**
     * Получить цвет типа
     */
    public function color(): string
    {
        return match($this) {
            self::MATERIAL_REQUEST => '#4CAF50',
            self::PERSONNEL_REQUEST => '#2196F3',
            self::EQUIPMENT_REQUEST => '#FF9800',
            self::INFO_REQUEST => '#9C27B0',
            self::ISSUE_REPORT => '#F44336',
            self::OTHER => '#9E9E9E',
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
                'color' => $case->color(),
            ],
            self::cases()
        );
    }
}

