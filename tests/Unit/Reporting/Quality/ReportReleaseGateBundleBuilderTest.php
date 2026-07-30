<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Quality;

use App\BusinessModules\Core\Reporting\Application\Quality\ReportReleaseGateBundleBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReportReleaseGateBundleBuilderTest extends TestCase
{
    public function test_builds_a_closed_fourteen_gate_bundle_with_the_9_4_1_ownership_map(): void
    {
        $bundle = (new ReportReleaseGateBundleBuilder())->build(
            $this->gates(),
            $this->qg14Evidence(),
            str_repeat('a', 40),
            [],
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );

        self::assertSame('release_gates_passed', $bundle->status);
        self::assertSame(['backend' => 9, 'admin' => 4, 'joint' => 1], $bundle->ownershipCounts);
        self::assertSame('both', $bundle->gates[13]->ownerPlan);
    }

    public function test_rejects_any_attempt_to_move_qg06_into_joint_ownership(): void
    {
        $gates = $this->gates();
        $gates[5] = $this->gate('QG-06', 'both', 46);

        $this->expectExceptionObject(new \App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE));

        (new ReportReleaseGateBundleBuilder())->build($gates, $this->qg14Evidence(), str_repeat('a', 40), [], new DateTimeImmutable('2026-07-26T00:00:00Z'));
    }

    /** @return list<ReportQualityGateEvidence> */
    private function gates(): array
    {
        $owners = ['backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'admin', 'admin', 'admin', 'admin', 'both'];
        $counts = [28, 56, 500, 28, 28, 46, 28, 28, 1, 28, 252, 25, 3, 0];

        return array_map(fn (int $index): ReportQualityGateEvidence => $this->gate(sprintf('QG-%02d', $index + 1), $owners[$index], $counts[$index]), array_keys($owners));
    }

    private function gate(string $id, string $owner, int $count): ReportQualityGateEvidence
    {
        return new ReportQualityGateEvidence($id, $owner, ReportQualityEvidencePhase::RELEASE, ReportQualityEvidenceStatus::PASSED, $id === 'QG-14' ? 'qg14_forbidden_symbols' : 'command-' . strtolower($id), $count, new Sha256Hash(str_repeat('b', 64)), str_repeat('a', 40), str_repeat('c', 40), new DateTimeImmutable('2026-07-26T00:00:00Z'), new Sha256Hash(str_repeat('d', 64)));
    }

    private function qg14Evidence(): JointQG14Evidence
    {
        return new JointQG14Evidence(0, 0, 0, new Sha256Hash(str_repeat('1', 64)), new Sha256Hash(str_repeat('2', 64)), new Sha256Hash(str_repeat('3', 64)), ['node', 'scripts/verify-reporting-cutover.mjs', '--admin-root=C:/admin', '--backend-root=C:/backend'], 'qg14_forbidden_symbols');
    }
}
