<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportEvidenceArtifactDescriptor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPrerequisiteEvidenceBundle;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\TestCase;

final class ReportPrerequisiteEvidenceBundleTest extends TestCase
{
    public function test_bundle_preserves_the_content_addressed_descriptor_contract(): void
    {
        $hash = new Sha256Hash(str_repeat('a', 64));
        $descriptor = new ReportEvidenceArtifactDescriptor(
            'plan-1b:pdf_renderer_budget',
            '1b',
            'gate_artifact',
            'artifacts/plan-1b-pdf-renderer-budget.json',
            $hash,
        );
        $planOneA = ['status' => 'passed'];
        $planOneB = ['status' => 'passed'];

        $bundle = new ReportPrerequisiteEvidenceBundle($hash, [$descriptor], $planOneA, $planOneB);

        self::assertSame($hash, $bundle->manifestHash);
        self::assertSame([$descriptor], $bundle->artifacts);
        self::assertSame($planOneA, $bundle->planOneACompletion);
        self::assertSame($planOneB, $bundle->planOneBCompletion);
    }
}
