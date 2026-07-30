<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use InvalidArgumentException;

final readonly class DrillDownPageCursor
{
    private function __construct(
        public ?int $contextOffset,
        public ?LineageCursorPosition $eventPosition,
    ) {}

    public static function context(int $offset): self
    {
        if ($offset < 1) {
            throw new InvalidArgumentException('drill_down_page_cursor_invalid');
        }

        return new self($offset, null);
    }

    public static function events(LineageCursorPosition $position): self
    {
        return new self(null, $position);
    }

    public static function decode(?string $cursor): ?self
    {
        if ($cursor === null) {
            return null;
        }
        if (preg_match('/^c:([1-9][0-9]*)$/D', $cursor, $matches) === 1) {
            return self::context((int) $matches[1]);
        }
        if (preg_match('/^e:([1-9][0-9]*):([1-9][0-9]*)$/D', $cursor, $matches) === 1) {
            return self::events(new LineageCursorPosition(
                (int) $matches[1],
                (int) $matches[2],
            ));
        }

        throw new InvalidArgumentException('drill_down_page_cursor_invalid');
    }

    public function encode(): string
    {
        if ($this->contextOffset !== null) {
            return 'c:'.$this->contextOffset;
        }
        $position = $this->eventPosition
            ?? throw new InvalidArgumentException('drill_down_page_cursor_invalid');

        return 'e:'.$position->encode();
    }
}
