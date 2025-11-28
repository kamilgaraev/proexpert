<?php

namespace App\BusinessModules\Features\SiteRequests\Enums;

/**
 * Статусы заявок с объекта
 */
enum SiteRequestStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case IN_REVIEW = 'in_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case IN_PROGRESS = 'in_progress';
    case FULFILLED = 'fulfilled';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case ON_HOLD = 'on_hold';

    /**
     * Получить человекочитаемое название
     */
    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Черновик',
            self::PENDING => 'Ожидает обработки',
            self::IN_REVIEW => 'На рассмотрении',
            self::APPROVED => 'Одобрена',
            self::REJECTED => 'Отклонена',
            self::IN_PROGRESS => 'В исполнении',
            self::FULFILLED => 'Выполнена',
            self::COMPLETED => 'Закрыта',
            self::CANCELLED => 'Отменена',
            self::ON_HOLD => 'Приостановлена',
        };
    }

    /**
     * Получить цвет статуса
     */
    public function color(): string
    {
        return match($this) {
            self::DRAFT => '#9E9E9E',
            self::PENDING => '#FF9800',
            self::IN_REVIEW => '#2196F3',
            self::APPROVED => '#4CAF50',
            self::REJECTED => '#F44336',
            self::IN_PROGRESS => '#03A9F4',
            self::FULFILLED => '#8BC34A',
            self::COMPLETED => '#4CAF50',
            self::CANCELLED => '#795548',
            self::ON_HOLD => '#607D8B',
        };
    }

    /**
     * Получить иконку статуса
     */
    public function icon(): string
    {
        return match($this) {
            self::DRAFT => 'file-alt',
            self::PENDING => 'clock',
            self::IN_REVIEW => 'search',
            self::APPROVED => 'check-circle',
            self::REJECTED => 'times-circle',
            self::IN_PROGRESS => 'spinner',
            self::FULFILLED => 'check-double',
            self::COMPLETED => 'flag-checkered',
            self::CANCELLED => 'ban',
            self::ON_HOLD => 'pause-circle',
        };
    }

    /**
     * Проверить, является ли статус начальным
     */
    public function isInitial(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Проверить, является ли статус конечным
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::REJECTED]);
    }

    /**
     * Проверить, можно ли редактировать заявку в этом статусе
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::PENDING]);
    }

    /**
     * Проверить, можно ли отменить заявку в этом статусе
     */
    public function isCancellable(): bool
    {
        return !in_array($this, [self::COMPLETED, self::CANCELLED, self::REJECTED]);
    }

    /**
     * Получить все статусы как массив для валидации
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Получить все статусы с метками для выбора
     */
    public static function options(): array
    {
        return array_map(
            fn(self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'icon' => $case->icon(),
                'is_final' => $case->isFinal(),
            ],
            self::cases()
        );
    }

    /**
     * Получить базовые переходы статусов (workflow по умолчанию)
     */
    public static function getDefaultTransitions(): array
    {
        return [
            self::DRAFT->value => [self::PENDING->value, self::CANCELLED->value],
            // Разрешаем прямой переход в APPROVED из PENDING
            self::PENDING->value => [self::IN_REVIEW->value, self::APPROVED->value, self::REJECTED->value, self::CANCELLED->value],
            self::IN_REVIEW->value => [self::APPROVED->value, self::REJECTED->value, self::PENDING->value],
            self::APPROVED->value => [self::IN_PROGRESS->value, self::COMPLETED->value, self::CANCELLED->value],
            self::IN_PROGRESS->value => [self::FULFILLED->value, self::ON_HOLD->value, self::CANCELLED->value],
            self::FULFILLED->value => [self::COMPLETED->value, self::IN_PROGRESS->value],
            self::ON_HOLD->value => [self::IN_PROGRESS->value, self::CANCELLED->value],
            self::COMPLETED->value => [],
            self::CANCELLED->value => [],
            self::REJECTED->value => [],
        ];
    }
}

