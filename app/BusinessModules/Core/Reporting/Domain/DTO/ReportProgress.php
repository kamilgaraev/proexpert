<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use Closure;
use InvalidArgumentException;

final class ReportProgress
{
    private int $state;

    public function __construct(
        int $percent,
        private readonly ?Closure $onAdvance = null,
    )
    {
        self::assertPercent($percent);

        $this->state = $percent;
    }

    public function percent(): int
    {
        return $this->state;
    }

    public function advance(int $percent): bool
    {
        self::assertPercent($percent);

        if ($percent < $this->state) {
            throw new InvalidArgumentException('report_progress_decrease_invalid');
        }

        if ($percent === $this->state) {
            return false;
        }

        $this->state = $percent;
        if ($this->onAdvance !== null) {
            ($this->onAdvance)($this);
        }

        return true;
    }

    private static function assertPercent(int $percent): void
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('report_progress_percent_invalid');
        }
    }
}
