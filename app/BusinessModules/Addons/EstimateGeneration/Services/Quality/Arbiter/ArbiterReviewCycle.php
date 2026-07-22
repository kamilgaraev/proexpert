<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use InvalidArgumentException;

final readonly class ArbiterReviewCycle
{
    /** @var list<string> */
    public array $targetPackageKeys;

    /**
     * @param list<string> $targetPackageKeys
     */
    public function __construct(
        public string $inputHash,
        public bool $attempted,
        array $targetPackageKeys,
        public string $status,
        public string $terminalOutcome,
    ) {
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $inputHash) !== 1) {
            throw new InvalidArgumentException('Invalid arbiter review input hash.');
        }
        if (! in_array($status, ['shadow_recommendation', 'evidence_required', 'cycle_exhausted'], true)) {
            throw new InvalidArgumentException('Invalid arbiter review cycle status.');
        }
        if (! in_array($terminalOutcome, ['targeted_rebuild', 'human_review'], true)) {
            throw new InvalidArgumentException('Invalid arbiter review terminal outcome.');
        }
        if (($status === 'shadow_recommendation') !== ($terminalOutcome === 'targeted_rebuild')) {
            throw new InvalidArgumentException('Invalid arbiter review cycle transition.');
        }

        $keys = [];
        foreach ($targetPackageKeys as $packageKey) {
            if (! is_string($packageKey) || preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $packageKey) !== 1) {
                throw new InvalidArgumentException('Invalid arbiter review target package key.');
            }
            $keys[$packageKey] = true;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        if (($terminalOutcome === 'targeted_rebuild') !== ($keys !== [])) {
            throw new InvalidArgumentException('Invalid arbiter review target package keys.');
        }

        $this->targetPackageKeys = $keys;
    }

    /** @return array{input_hash: string, attempted: bool, target_package_keys: list<string>, status: string, terminal_outcome: string} */
    public function toArray(): array
    {
        return [
            'input_hash' => $this->inputHash,
            'attempted' => $this->attempted,
            'target_package_keys' => $this->targetPackageKeys,
            'status' => $this->status,
            'terminal_outcome' => $this->terminalOutcome,
        ];
    }
}
