<?php

namespace App\Enums\Schedule;

enum TaskTypeEnum: string
{
    case TASK = 'task';
    case MILESTONE = 'milestone';
    case SUMMARY = 'summary';
    case CONTAINER = 'container';

    public function label(): string
    {
        return match($this) {
            self::TASK => 'Задача',
            self::MILESTONE => 'Веха',
            self::SUMMARY => 'Суммарная задача',
            self::CONTAINER => 'Контейнер',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::TASK => 'Обычная рабочая задача с длительностью',
            self::MILESTONE => 'Контрольная точка без длительности',
            self::SUMMARY => 'Группирующая задача с подзадачами',
            self::CONTAINER => 'Организационная группа задач',
        };
    }

    public function hasChildren(): bool
    {
        return in_array($this, [self::SUMMARY, self::CONTAINER]);
    }

    public function hasDuration(): bool
    {
        return $this !== self::MILESTONE;
    }

    public function allowsProgress(): bool
    {
        return $this === self::TASK;
    }

    public function allowsResources(): bool
    {
        return $this === self::TASK;
    }

    public function icon(): string
    {
        return match($this) {
            self::TASK => 'task',
            self::MILESTONE => 'flag',
            self::SUMMARY => 'folder',
            self::CONTAINER => 'collection',
        };
    }
} 