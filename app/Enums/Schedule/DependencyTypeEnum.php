<?php

namespace App\Enums\Schedule;

enum DependencyTypeEnum: string
{
    case FINISH_TO_START = 'FS';
    case START_TO_START = 'SS';
    case FINISH_TO_FINISH = 'FF';
    case START_TO_FINISH = 'SF';

    public function label(): string
    {
        return match($this) {
            self::FINISH_TO_START => 'Окончание - Начало',
            self::START_TO_START => 'Начало - Начало',
            self::FINISH_TO_FINISH => 'Окончание - Окончание',
            self::START_TO_FINISH => 'Начало - Окончание',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::FINISH_TO_START => 'Последующая задача может начаться только после окончания предшествующей',
            self::START_TO_START => 'Последующая задача может начаться только после начала предшествующей',
            self::FINISH_TO_FINISH => 'Последующая задача может закончиться только после окончания предшествующей',
            self::START_TO_FINISH => 'Последующая задача может закончиться только после начала предшествующей',
        };
    }

    public function shortDescription(): string
    {
        return match($this) {
            self::FINISH_TO_START => 'Задача Б начинается после окончания задачи А',
            self::START_TO_START => 'Задача Б начинается после начала задачи А',
            self::FINISH_TO_FINISH => 'Задача Б заканчивается после окончания задачи А',
            self::START_TO_FINISH => 'Задача Б заканчивается после начала задачи А',
        };
    }

    public function isCommon(): bool
    {
        return $this === self::FINISH_TO_START;
    }

    public function requiresSimultaneousExecution(): bool
    {
        return in_array($this, [self::START_TO_START, self::FINISH_TO_FINISH]);
    }

    public function constraintPoint(): array
    {
        return match($this) {
            self::FINISH_TO_START => ['predecessor' => 'finish', 'successor' => 'start'],
            self::START_TO_START => ['predecessor' => 'start', 'successor' => 'start'],
            self::FINISH_TO_FINISH => ['predecessor' => 'finish', 'successor' => 'finish'],
            self::START_TO_FINISH => ['predecessor' => 'start', 'successor' => 'finish'],
        };
    }

    public static function commonTypes(): array
    {
        return [self::FINISH_TO_START, self::START_TO_START];
    }

    public static function advancedTypes(): array
    {
        return [self::FINISH_TO_FINISH, self::START_TO_FINISH];
    }
} 