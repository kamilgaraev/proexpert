<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use InvalidArgumentException;

final readonly class ArbiterRemediationState
{
    /** @var list<string> */
    public array $targetPackageKeys;

    /**
     * @param list<string> $targetPackageKeys
     */
    public function __construct(
        public string $rootInputHash,
        array $targetPackageKeys,
        public bool $rebuildAttempted,
        public string $phase,
        public ?string $reviewOutcome,
    ) {
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $rootInputHash) !== 1) {
            throw new InvalidArgumentException('Invalid arbiter remediation root input hash.');
        }
        if (! in_array($phase, ['attempted', 'reviewed'], true)) {
            throw new InvalidArgumentException('Invalid arbiter remediation phase.');
        }
        if ($phase === 'attempted' && $reviewOutcome !== null) {
            throw new InvalidArgumentException('Invalid attempted arbiter remediation outcome.');
        }
        if ($phase === 'reviewed' && ! in_array($reviewOutcome, ['passed', 'confirmed_scope_only', 'human_review'], true)) {
            throw new InvalidArgumentException('Invalid reviewed arbiter remediation outcome.');
        }

        $keys = [];
        foreach ($targetPackageKeys as $packageKey) {
            if (! is_string($packageKey) || preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $packageKey) !== 1) {
                throw new InvalidArgumentException('Invalid arbiter remediation target package key.');
            }
            $keys[$packageKey] = true;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        if ($keys === []) {
            throw new InvalidArgumentException('Arbiter remediation target package keys are required.');
        }

        $this->targetPackageKeys = $keys;
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        $keys = array_keys($state);
        sort($keys, SORT_STRING);
        if ($keys !== ['phase', 'rebuild_attempted', 'review_outcome', 'root_input_hash', 'target_package_keys']) {
            throw new InvalidArgumentException('Invalid arbiter remediation state.');
        }
        if (! is_string($state['root_input_hash'])
            || ! is_array($state['target_package_keys'])
            || ! is_bool($state['rebuild_attempted'])
            || ! is_string($state['phase'])
            || (! is_string($state['review_outcome']) && $state['review_outcome'] !== null)) {
            throw new InvalidArgumentException('Invalid arbiter remediation state types.');
        }

        return new self(
            $state['root_input_hash'],
            $state['target_package_keys'],
            $state['rebuild_attempted'],
            $state['phase'],
            $state['review_outcome'],
        );
    }

    /** @return array{root_input_hash: string, target_package_keys: list<string>, rebuild_attempted: bool, phase: string, review_outcome: null|string} */
    public function toArray(): array
    {
        return [
            'root_input_hash' => $this->rootInputHash,
            'target_package_keys' => $this->targetPackageKeys,
            'rebuild_attempted' => $this->rebuildAttempted,
            'phase' => $this->phase,
            'review_outcome' => $this->reviewOutcome,
        ];
    }
}
