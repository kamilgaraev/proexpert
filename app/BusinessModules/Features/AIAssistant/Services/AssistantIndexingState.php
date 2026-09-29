<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantIndexingState
{
    private int $migrationDepth = 0;

    public function beginMigration(): void
    {
        $this->migrationDepth++;
    }

    public function endMigration(): void
    {
        $this->migrationDepth = max(0, $this->migrationDepth - 1);
    }

    public function paused(): bool
    {
        return $this->migrationDepth > 0;
    }
}
