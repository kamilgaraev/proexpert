<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use Illuminate\Support\Collection;

interface ProjectPulseFactSourceInterface
{
    public function key(): string;

    public function collect(ProjectPulseContext $context): Collection;
}
