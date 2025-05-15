<?php

namespace App\Traits\Enums;

trait HasValues
{
    /**
     * Get all case values.
     *
     * @return array<int, string|int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all case names.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get an associative array of [value => name].
     *
     * @return array<string|int, string>
     */
    public static function associative(): array
    {
        return array_combine(self::values(), self::names());
    }
} 