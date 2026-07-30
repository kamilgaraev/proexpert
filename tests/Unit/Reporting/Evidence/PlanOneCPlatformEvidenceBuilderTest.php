<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPlatformEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPrerequisiteEvidenceValidator;
use App\BusinessModules\Core\Reporting\Domain\DTO\TrackedPlanDocument;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlanOneCPlatformEvidenceBuilderTest extends TestCase
{
    public function test_rejects_legacy_count_only_platform_evidence_payload(): void
    {
        $root = dirname(__DIR__, 4);
        $bundle = (new PlanOneCPrerequisiteEvidenceValidator($root))
            ->validateBundle($root.'/tests/Fixtures/Reporting/Prerequisites/artifact-bundle.valid.json');
        $commit = str_repeat('1', 40);
        $planOneB = new TrackedPlanDocument(
            'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md',
            $commit,
            new Sha256Hash(PlanOneCPlatformEvidenceBuilder::PLAN_ONE_B_SHA256),
            '',
        );
        $planOneC = new TrackedPlanDocument(
            'docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md',
            $commit,
            new Sha256Hash(str_repeat('a', 64)),
            '',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plan_one_c_platform_evidence_invalid');

        (new PlanOneCPlatformEvidenceBuilder($root))->build(
            $bundle,
            $planOneB,
            $planOneC,
            $commit,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            [
                'published_count' => 0,
                'binding_count' => 0,
                'unresolved_risks' => [],
            ],
        );
    }
}
