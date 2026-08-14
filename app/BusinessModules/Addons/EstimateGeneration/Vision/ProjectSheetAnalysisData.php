<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\FacadeSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\PlanSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\SectionSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\SheetAnalysisContract;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\SheetAnalysisFact;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\SpecificationSheetAnalysis;
use App\BusinessModules\Addons\EstimateGeneration\Vision\SheetAnalysis\UnknownSheetAnalysis;

final readonly class ProjectSheetAnalysisData
{
    public const CONTRACT_VERSION = 'sheet-analysis:v3';

    /** @param list<array<string, mixed>> $facts @param list<array{section: string, index: int, reason: string}> $quarantinedItems */
    private function __construct(
        public string $sheetRole,
        public array $facts,
        public SheetAnalysisContract $roleAnalysis,
        public array $quarantinedItems = [],
    ) {}

    /** @param array<string, mixed> $data @param list<string> $evidenceKeys @param list<string> $nativeReferences */
    public static function fromProviderArray(
        array $data,
        array $evidenceKeys,
        int $maxFacts = 500,
        array $nativeReferences = [],
    ): self {
        $normalized = ProjectSheetAnalysisValidator::normalizeProvider($data, $evidenceKeys, $maxFacts, $nativeReferences);
        $facts = array_map(SheetAnalysisFact::fromValidatedArray(...), $normalized['facts']);

        return self::fromTyped($normalized['role'], $facts, $normalized['quarantined']);
    }

    /** @param array<string, mixed> $data @param list<string> $evidenceKeys */
    public static function fromStoredArray(array $data, array $evidenceKeys, int $maxFacts = 500): self
    {
        $nativeReferences = [];
        foreach (($data['facts'] ?? []) as $fact) {
            $reference = is_array($fact) ? ($fact['sourcePolygonOrNativeRef'] ?? null) : null;
            if (is_string($reference)) {
                $nativeReferences[] = $reference;
            }
        }

        ProjectSheetAnalysisValidator::assertValid(
            $data,
            $evidenceKeys,
            $maxFacts,
            array_values(array_unique($nativeReferences)),
        );

        return self::fromTyped(
            $data['role'],
            array_map(SheetAnalysisFact::fromValidatedArray(...), $data['facts']),
        );
    }

    public function mapPolygonsToSource(ProjectiveTransformData $transform): self
    {
        return self::fromTyped(
            $this->sheetRole,
            array_map(static fn (SheetAnalysisFact $fact): SheetAnalysisFact => $fact->mapPolygonToSource($transform), $this->roleAnalysis->facts()),
            $this->quarantinedItems,
        );
    }

    /** @return array{contractVersion: string, role: string, facts: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['contractVersion' => self::CONTRACT_VERSION, 'role' => $this->sheetRole, 'facts' => $this->facts];
    }

    /** @param list<SheetAnalysisFact> $facts @param list<array{section: string, index: int, reason: string}> $quarantinedItems */
    private static function fromTyped(string $role, array $facts, array $quarantinedItems = []): self
    {
        $typed = match ($role) {
            'plan' => new PlanSheetAnalysis($facts),
            'section' => new SectionSheetAnalysis($facts),
            'facade' => new FacadeSheetAnalysis($facts),
            'explication', 'specification' => new SpecificationSheetAnalysis($role, $facts),
            'unknown' => new UnknownSheetAnalysis($facts),
        };

        return new self($role, array_map(static fn (SheetAnalysisFact $fact): array => $fact->toArray(), $facts), $typed, $quarantinedItems);
    }
}
