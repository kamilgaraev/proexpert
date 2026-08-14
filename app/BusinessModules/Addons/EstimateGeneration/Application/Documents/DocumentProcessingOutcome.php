<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentProcessingOutcome
{
    /** @param array{included: int, ready: int, needs_user_action: int, terminal_system_failed: int, breaker_stopped: int, system_failed: int, processing: int, excluded: int} $counts */
    public function __construct(
        public string $type,
        public string $documentStatus,
        public int $processedPages,
        public array $counts,
        public string $state,
        public ?string $errorCode = null,
        public ?string $errorMessageKey = null,
        public bool $retryAllowed = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $completed = max(0, $this->counts['included'] - $this->counts['processing']);
        $executionProgress = $this->counts['included'] === 0
            ? 100
            : (int) floor(($completed / $this->counts['included']) * 100);

        return [
            'type' => $this->type,
            'state' => $this->state,
            'counts' => $this->counts,
            'retry_allowed' => $this->retryAllowed,
            'execution_progress_percent' => $executionProgress,
            'readiness' => match ($this->type) {
                'ready' => 'ready',
                'processing' => 'processing',
                'user_action_required' => 'review_required',
                default => 'blocked',
            },
            'is_ready' => $this->type === 'ready',
        ];
    }
}
