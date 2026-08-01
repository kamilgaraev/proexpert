<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;

final readonly class ProjectSheetAnalysisData
{
    public const SCHEMA_VERSION = 1;

    /** @param list<array<string, mixed>> $facts */
    private function __construct(
        public string $sheetRole,
        public array $facts,
    ) {
    }

    /** @param array<string, mixed> $data @param list<string> $evidenceKeys */
    public static function fromProviderArray(array $data, array $evidenceKeys): self
    {
        ProjectSheetAnalysisValidator::assertValid($data, $evidenceKeys);

        return new self($data['sheet_role'], $data['facts']);
    }

    public function mapPolygonsToSource(ProjectiveTransformData $transform): self
    {
        $facts = array_map(static function (array $fact) use ($transform): array {
            $fact['polygon'] = array_map($transform->toSource(...), $fact['polygon']);

            return $fact;
        }, $this->facts);

        return new self($this->sheetRole, $facts);
    }

    /** @return array{schema_version: int, sheet_role: string, facts: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'sheet_role' => $this->sheetRole, 'facts' => $this->facts];
    }
}
