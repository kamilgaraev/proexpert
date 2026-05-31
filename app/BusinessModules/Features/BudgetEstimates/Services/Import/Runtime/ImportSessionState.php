<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

use App\Models\ImportSession;

final readonly class ImportSessionState
{
    public function __construct(private ImportSession $session) {}

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->session->options ?? [];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function mergeOptions(array $values): void
    {
        $this->session->update([
            'options' => array_replace_recursive($this->options(), $values),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->session->stats ?? [];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function mergeStats(array $values): void
    {
        $this->session->update([
            'stats' => array_replace_recursive($this->stats(), $values),
        ]);
    }
}
