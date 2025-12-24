<?php

namespace App\Enums;

enum ContractorType: string
{
    case MANUAL = 'manual';
    case INVITED_ORGANIZATION = 'invited_organization';
    case HOLDING_MEMBER = 'holding_member';
    case SELF_EXECUTION = 'self_execution';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::MANUAL => 'Ручной ввод',
            self::INVITED_ORGANIZATION => 'Приглашенная организация',
            self::HOLDING_MEMBER => 'Участник холдинга',
            self::SELF_EXECUTION => 'Собственные силы',
        };
    }

    /**
     * Получить описание типа
     */
    public function description(): string
    {
        return match($this) {
            self::MANUAL => 'Подрядчик, созданный вручную пользователем',
            self::INVITED_ORGANIZATION => 'Подрядчик из внешней приглашенной организации',
            self::HOLDING_MEMBER => 'Подрядчик - участник холдинга (головная/дочерняя организация)',
            self::SELF_EXECUTION => 'Работы выполняются собственными силами компании (хозяйственный способ)',
        };
    }

    /**
     * Проверяет, можно ли редактировать данные подрядчика
     */
    public function isEditable(): bool
    {
        return match($this) {
            self::MANUAL => true,
            self::INVITED_ORGANIZATION => false,
            self::HOLDING_MEMBER => false,
            self::SELF_EXECUTION => false,
        };
    }

    /**
     * Проверяет, можно ли удалить подрядчика
     */
    public function isDeletable(): bool
    {
        return match($this) {
            self::MANUAL => true,
            self::INVITED_ORGANIZATION => true,
            self::HOLDING_MEMBER => false, // Удаляется только при удалении из холдинга
            self::SELF_EXECUTION => false, // Удаляется только при удалении организации
        };
    }

    /**
     * Проверяет, нужна ли автоматическая синхронизация данных
     */
    public function needsAutoSync(): bool
    {
        return match($this) {
            self::MANUAL => false,
            self::INVITED_ORGANIZATION => true,
            self::HOLDING_MEMBER => true,
            self::SELF_EXECUTION => false,
        };
    }

    /**
     * Получить все типы для dropdown/select
     */
    public static function toArray(): array
    {
        return array_map(
            fn(self $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ],
            self::cases()
        );
    }
}

