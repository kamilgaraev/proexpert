<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions;

use InvalidArgumentException;

final readonly class EstimateDecision
{
    public function __construct(
        public int $id,
        public string $sessionId,
        public string $decisionKey,
        public int $version,
        public array $before,
        public array $after,
        public string $reason,
        public ActorContext $actor,
        public string $sourceCommand,
        public string $occurredAt,
        public ?int $revertedDecisionId = null,
        public bool $idempotent = false,
        public ?string $stableKey = null,
        public array $dependencyImpacts = [],
    ) {
        if ($id < 1 || preg_match('/^[1-9][0-9]*$/', $sessionId) !== 1
            || preg_match('/^[a-z][a-z0-9:_-]{0,191}$/', $decisionKey) !== 1
            || $version < 1 || trim($reason) === '' || mb_strlen($reason) > 1000
            || ! in_array($sourceCommand, ['apply', 'revert'], true)
            || ($sourceCommand === 'apply' && $revertedDecisionId !== null)
            || ($sourceCommand === 'revert' && ($revertedDecisionId ?? 0) < 1)) {
            throw new InvalidArgumentException('Estimate decision is invalid.');
        }
    }

    public function fingerprint(): string
    {
        return self::fingerprintFor(
            $this->sessionId,
            $this->decisionKey,
            $this->before,
            $this->after,
            $this->reason,
            $this->sourceCommand,
            $this->revertedDecisionId,
        );
    }

    /** @return array{idempotent:bool,correction:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'idempotent' => $this->idempotent,
            'correction' => [
                'id' => $this->id,
                'stable_key' => $this->stableKey ?? 'decision:'.$this->id,
                'operation' => $this->sourceCommand,
                'previous_canonical_value' => $this->before,
                'new_canonical_value' => $this->after,
                'dependency_impacts' => $this->dependencyImpacts,
                'reverted_correction_id' => $this->revertedDecisionId,
                'reason' => $this->reason,
                'actor_id' => $this->actor->actorId,
                'created_at' => $this->occurredAt,
            ],
        ];
    }

    public static function fingerprintFor(
        string $sessionId,
        string $decisionKey,
        array $before,
        array $after,
        string $reason,
        string $sourceCommand,
        ?int $revertedDecisionId,
    ): string {
        return hash('sha256', self::canonicalJson([
            'session_id' => $sessionId,
            'decision_key' => $decisionKey,
            'before' => $before,
            'after' => $after,
            'reason' => trim($reason),
            'source_command' => $sourceCommand,
            'reverted_decision_id' => $revertedDecisionId,
        ]));
    }

    private static function canonicalJson(array $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }
}
