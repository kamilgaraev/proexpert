<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use InvalidArgumentException;

final readonly class TargetedSheetRecheckScope
{
    public const CONTRACT_VERSION = 'targeted-sheet-recheck:v1';

    /** @param list<string> $sourceSet */
    private function __construct(
        public string $role,
        public string $reason,
        public array $sourceSet,
        public ?string $entityKey,
    ) {
        if (! in_array($role, ['plan', 'section', 'facade', 'explication', 'specification', 'unknown'], true)
            || ! in_array($reason, ['sheet_role_conflict', 'sheet_role_insufficient_evidence'], true)
            || $sourceSet === [] || count($sourceSet) > 2 || count($sourceSet) !== count(array_unique($sourceSet))) {
            throw new InvalidArgumentException('Invalid targeted sheet recheck scope.');
        }
        foreach ($sourceSet as $source) {
            if (preg_match('~^document:[1-9][0-9]*/sheet:[1-9][0-9]*$~', $source) !== 1) {
                throw new InvalidArgumentException('Invalid targeted sheet source.');
            }
        }
        if (($entityKey !== null && (count($sourceSet) !== 1 || preg_match('~^[a-z0-9][a-z0-9._:-]{0,79}$~', $entityKey) !== 1))
            || ($entityKey === null && count($sourceSet) !== 2)) {
            throw new InvalidArgumentException('Targeted recheck must address one entity or one sheet pair.');
        }
    }

    public static function forEntity(string $role, string $reason, string $entityKey, string $source): self
    {
        return new self($role, $reason, [$source], $entityKey);
    }

    public static function forSheetPair(string $role, string $reason, string ...$sources): self
    {
        return new self($role, $reason, $sources, null);
    }

    /** @return array{contract_version: string, role: string, reason: string, source_set: list<string>, entity_key: ?string} */
    public function toSafeUsageContext(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'role' => $this->role,
            'reason' => $this->reason,
            'source_set' => $this->sourceSet,
            'entity_key' => $this->entityKey,
        ];
    }
}
