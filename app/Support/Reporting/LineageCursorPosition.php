<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use InvalidArgumentException;

final readonly class LineageCursorPosition
{
    public function __construct(
        public int $version,
        public int $id,
    ) {
        if (min($version, $id) < 1) {
            throw new InvalidArgumentException('lineage_cursor_position_invalid');
        }
    }

    public static function decode(?string $cursor): ?self
    {
        if ($cursor === null) {
            return null;
        }
        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $cursor, $matches) !== 1) {
            throw new InvalidArgumentException('lineage_cursor_position_invalid');
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function encode(): string
    {
        return $this->version.':'.$this->id;
    }
}
