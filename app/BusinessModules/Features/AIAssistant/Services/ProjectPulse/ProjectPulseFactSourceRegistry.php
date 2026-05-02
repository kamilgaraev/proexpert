<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use Illuminate\Support\Collection;

class ProjectPulseFactSourceRegistry
{
    public function __construct(
        private readonly iterable $sources,
    ) {
    }

    public function all(): Collection
    {
        return collect($this->sources)
            ->filter(fn (mixed $source) => $source instanceof ProjectPulseFactSourceInterface)
            ->values();
    }
}
