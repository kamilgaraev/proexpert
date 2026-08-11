<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use InvalidArgumentException;

final readonly class ProjectUnderstandingResult
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_UNRESOLVED = 'unresolved';

    public const STATUS_STALE = 'stale';

    public array $links;

    public array $conflicts;

    public array $questions;

    public array $limitations;

    public function __construct(
        array $links,
        array $conflicts,
        array $questions,
        array $limitations,
        public int $providerCalls,
        public string $status = self::STATUS_UNRESOLVED,
        public ?string $sourceVersion = null,
        public ?string $inputFingerprint = null,
    ) {
        if ($providerCalls < 0 || ! in_array($status, [self::STATUS_CURRENT, self::STATUS_UNRESOLVED, self::STATUS_STALE], true)
            || ($sourceVersion !== null && preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1)
            || ($inputFingerprint !== null && preg_match('/^[a-f0-9]{64}$/D', $inputFingerprint) !== 1)
            || ($status === self::STATUS_CURRENT && ($sourceVersion === null || $inputFingerprint === null))) {
            throw new InvalidArgumentException('Project understanding result contract is invalid.');
        }
        foreach ($conflicts as $conflict) {
            if (! $conflict instanceof Conflict) {
                throw new InvalidArgumentException('Project understanding conflict is invalid.');
            }
        }
        usort($links, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($conflicts, static fn (Conflict $left, Conflict $right): int => $left->id <=> $right->id);
        usort($questions, static fn (array $left, array $right): int => $left['conflict_id'] <=> $right['conflict_id']);
        $limitations = array_values(array_unique($limitations));
        sort($limitations, SORT_STRING);
        $this->links = $links;
        $this->conflicts = $conflicts;
        $this->questions = $questions;
        $this->limitations = $limitations;
    }

    public static function current(
        string $sourceVersion,
        string $inputFingerprint,
        array $links,
        array $conflicts,
        array $questions,
        int $providerCalls,
        array $limitations = [],
    ): self {
        return new self(
            $links,
            $conflicts,
            $questions,
            $limitations,
            $providerCalls,
            self::STATUS_CURRENT,
            $sourceVersion,
            $inputFingerprint,
        );
    }

    public static function unresolved(
        array $limitations,
        array $links = [],
        array $conflicts = [],
        array $questions = [],
        int $providerCalls = 0,
        ?string $sourceVersion = null,
        ?string $inputFingerprint = null,
    ): self {
        return new self(
            $links,
            $conflicts,
            $questions,
            $limitations,
            $providerCalls,
            self::STATUS_UNRESOLVED,
            $sourceVersion,
            $inputFingerprint,
        );
    }

    public static function stale(array $limitations, int $providerCalls = 0): self
    {
        return new self([], [], [], $limitations, $providerCalls, self::STATUS_STALE);
    }

    public function isReadyForPlanning(): bool
    {
        return $this->status === self::STATUS_CURRENT
            && $this->sourceVersion !== null
            && $this->inputFingerprint !== null;
    }
}
