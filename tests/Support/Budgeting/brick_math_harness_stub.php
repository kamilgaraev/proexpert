<?php

declare(strict_types=1);

namespace Brick\Math\Exception {
    class MathException extends \RuntimeException
    {
    }
}

namespace Brick\Math {
    use Brick\Math\Exception\MathException;

    final class RoundingMode
    {
        public const HalfUp = 'half_up';
    }

    final class BigDecimal
    {
        private function __construct(private float $value, private ?int $scale = null)
        {
        }

        public static function of(mixed $value): self
        {
            return new self(self::numeric($value));
        }

        public static function zero(): self
        {
            return new self(0.0);
        }

        public static function one(): self
        {
            return new self(1.0);
        }

        public function plus(mixed $value): self
        {
            return new self($this->value + self::numeric($value));
        }

        public function minus(mixed $value): self
        {
            return new self($this->value - self::numeric($value));
        }

        public function multipliedBy(mixed $value): self
        {
            return new self($this->value * self::numeric($value));
        }

        public function dividedBy(mixed $value, int $scale, mixed $roundingMode): self
        {
            $divisor = self::numeric($value);
            if ($divisor === 0.0) {
                throw new MathException('division_by_zero');
            }

            return (new self($this->value / $divisor))->toScale($scale, $roundingMode);
        }

        public function toScale(int $scale, mixed $roundingMode): self
        {
            return new self(round($this->value, $scale, PHP_ROUND_HALF_UP), $scale);
        }

        public function isZero(): bool
        {
            return $this->value === 0.0;
        }

        public function isNegative(): bool
        {
            return $this->value < 0.0;
        }

        public function isGreaterThan(mixed $value): bool
        {
            return $this->value > self::numeric($value);
        }

        public function compareTo(mixed $value): int
        {
            return $this->value <=> self::numeric($value);
        }

        public function abs(): self
        {
            return new self(abs($this->value));
        }

        public function __toString(): string
        {
            if ($this->scale !== null) {
                return number_format($this->value, $this->scale, '.', '');
            }

            return rtrim(rtrim(sprintf('%.14F', $this->value), '0'), '.');
        }

        private static function numeric(mixed $value): float
        {
            if ($value instanceof self) {
                return $value->value;
            }
            if ((! is_int($value) && ! is_float($value) && ! is_string($value))
                || ! is_numeric($value)) {
                throw new MathException('invalid_decimal');
            }
            $numeric = (float) $value;
            if (! is_finite($numeric)) {
                throw new MathException('invalid_decimal');
            }

            return $numeric;
        }
    }
}
