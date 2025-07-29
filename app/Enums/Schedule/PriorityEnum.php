<?php

namespace App\Enums\Schedule;

enum PriorityEnum: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match($this) {
            self::LOW => 'Низкий',
            self::NORMAL => 'Обычный',
            self::HIGH => 'Высокий',
            self::CRITICAL => 'Критический',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::LOW => '#9CA3AF',     // серый
            self::NORMAL => '#3B82F6',  // синий
            self::HIGH => '#F59E0B',    // оранжевый
            self::CRITICAL => '#EF4444', // красный
        };
    }

    public function weight(): int
    {
        return match($this) {
            self::LOW => 1,
            self::NORMAL => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::LOW => 'arrow-down',
            self::NORMAL => 'minus',
            self::HIGH => 'arrow-up',
            self::CRITICAL => 'exclamation-triangle',
        };
    }

    public function requiresImmediateAttention(): bool
    {
        return $this === self::CRITICAL;
    }

    public function requiresEscalation(): bool
    {
        return in_array($this, [self::HIGH, self::CRITICAL]);
    }

    public static function sortByPriority(array $items, string $priorityField = 'priority'): array
    {
        usort($items, function($a, $b) use ($priorityField) {
            $priorityA = is_array($a) ? $a[$priorityField] : $a->$priorityField;
            $priorityB = is_array($b) ? $b[$priorityField] : $b->$priorityField;
            
            $weightA = self::from($priorityA)->weight();
            $weightB = self::from($priorityB)->weight();
            
            return $weightB <=> $weightA; // Сортировка по убыванию приоритета
        });
        
        return $items;
    }

    public static function higherThan(self $priority): array
    {
        $priorities = [self::LOW, self::NORMAL, self::HIGH, self::CRITICAL];
        $index = array_search($priority, $priorities);
        
        return array_slice($priorities, $index + 1);
    }
} 