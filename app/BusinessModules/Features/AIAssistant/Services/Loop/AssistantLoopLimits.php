<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use InvalidArgumentException;

final readonly class AssistantLoopLimits
{
    public function __construct(
        public int $steps = 16,
        public int $toolCalls = 8,
        public int $repairs = 2,
        public int $milliseconds = 30000,
        public int $totalTokens = 200000,
    ) {
        if ($steps < 1 || $steps > 128 || $toolCalls < 1 || $toolCalls > 64 || $repairs < 0 || $repairs > 8 || $milliseconds < 1 || $milliseconds > 120000 || $totalTokens < 1 || $totalTokens > 2000000) {
            throw new InvalidArgumentException('loop_limits_invalid');
        }
    }
}
