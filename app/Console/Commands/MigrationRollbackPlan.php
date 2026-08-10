<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Closure;

final readonly class MigrationRollbackPlan
{
    public function __construct(
        public int $rollbackSteps,
        public bool $requiresFixForward,
        public array $preservedMigrations,
    ) {}

    public static function forApplied(array $migrations, Closure $isForwardOnly): self
    {
        $lastForwardOnly = null;
        foreach ($migrations as $index => $migration) {
            if ($isForwardOnly($migration)) {
                $lastForwardOnly = $index;
            }
        }
        if ($lastForwardOnly === null) {
            return new self(count($migrations), false, []);
        }

        return new self(
            count($migrations) - $lastForwardOnly - 1,
            true,
            array_slice($migrations, 0, $lastForwardOnly + 1),
        );
    }
}
