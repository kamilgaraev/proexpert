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

    public function advanceProportion(
        int $completed,
        int $total,
        int $startPercent = 10,
        int $endPercent = 90,
    ): bool {
        self::assertPercent($startPercent);
        self::assertPercent($endPercent);
        if ($total < 1 || $completed < 0 || $completed > $total || $startPercent > $endPercent) {
            throw new InvalidArgumentException('report_progress_proportion_invalid');
        }

        $percent = $startPercent + intdiv(
            ($endPercent - $startPercent) * $completed,
            $total,
        );

        return $this->advance(max($this->state, $percent));
    }

    private static function assertPercent(int $percent): void
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('report_progress_percent_invalid');
        }
    }
}
