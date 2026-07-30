<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PlanOneCPrerequisiteContractTest extends TestCase
{
    private const PLAN_ONE_B_SHA256 = '58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626';

    private const PLAN_DOCUMENTS = [
        'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md',
        'docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md',
    ];

    public function test_lock_has_an_exact_current_prerequisite_set(): void
    {
        $lock = $this->lock();
        $required = $lock['required_prerequisites'];

        self::assertSame(27, $required['bundle_descriptor_count']);
        self::assertSame(20, $required['plan_1b_gate_artifact_count']);
        self::assertSame(self::PLAN_ONE_B_SHA256, $required['plan_1b_plan_sha256']);
        self::assertCount(20, $required['plan_1b_required_gate_ids']);
        self::assertSame(
            $required['plan_1b_required_gate_ids'],
            array_values(array_unique($required['plan_1b_required_gate_ids'])),
        );
    }

    public function test_plan_contract_is_pinned_to_the_current_malformed_matrix_without_dynamic_weakening(): void
    {
        $plan = $this->contents(self::PLAN_DOCUMENTS[1]);

        self::assertStringContainsString('malformed requests are exact `20/20`', $plan);
        self::assertStringContainsString('exact `cases=20`, `passed=20`, `http_requests=38`, `assertions=120`', $plan);
        self::assertStringContainsString('counts other than exact `22/22` and `20/20`', $plan);
        self::assertStringContainsString(self::PLAN_ONE_B_SHA256, $plan);
        self::assertStringNotContainsString('16/16', $plan);
        self::assertStringNotContainsString('6e674c7bbaeef5ae9cd52b1ce78ce7c89ff0734c166e5749acc1e5a8419ec1ce', $plan);
    }

    public function test_plan_documents_are_tracked_and_their_working_bytes_equal_head_blobs(): void
    {
        foreach (self::PLAN_DOCUMENTS as $relativePath) {
            $tracked = new Process(['git', 'ls-files', '--error-unmatch', $relativePath], $this->root());
            $tracked->run();
            self::assertSame(0, $tracked->getExitCode(), $tracked->getErrorOutput());

            $blob = new Process(['git', 'show', 'HEAD:'.$relativePath], $this->root());
            $blob->run();
            self::assertSame(0, $blob->getExitCode(), $blob->getErrorOutput());
            self::assertSame($blob->getOutput(), $this->contents($relativePath), $relativePath);
        }
    }

    public function test_lock_sidecar_binds_the_exact_raw_lock_bytes(): void
    {
        self::assertSame(
            hash_file('sha256', $this->root().'/docs/reports/contracts/plan-1c-contract-lock.json')."\n",
            $this->contents('docs/reports/contracts/plan-1c-contract-lock.sha256'),
        );
    }

    private function lock(): array
    {
        return json_decode(
            $this->contents('docs/reports/contracts/plan-1c-contract-lock.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function contents(string $relativePath): string
    {
        return (string) file_get_contents($this->root().'/'.$relativePath);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
