<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use LogicException;

final class AssistantLoopTrace
{
    private array $events = [];

    public function add(string $action, int $step, int $tokens, ?string $callRef = null): void
    {
        if (!in_array($action, ['plan', 'tool', 'refine', 'summary', 'final', 'repair', 'ready', 'blocked'], true) || $step < 0 || $tokens < 0 || ($callRef !== null && preg_match('/\Aref_[a-f0-9]{32}\z/D', $callRef) !== 1)) {
            throw new LogicException('trace_invalid');
        }
        $this->events[] = ['action' => $action, 'step' => $step, 'tokens' => $tokens, 'callRef' => $callRef];
    }

    public function events(): array
    {
        return $this->events;
    }
}
