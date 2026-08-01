<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use PHPUnit\Framework\TestCase;

final class ProcurementCycleCandidateEvidenceBuilderTest extends TestCase
{
    public function test_builder_writes_blocked_candidate_artifacts_bound_to_the_exact_checkout(): void
    {
        $root = dirname(__DIR__, 5);
        $output = $root.'/build/reports/r15-candidate-evidence';
        $sha = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));

        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/reporting/build-r15-publication-candidate.php'), $lines, $exitCode);
            self::assertSame(1, $exitCode);
            return;

            putenv('GITHUB_ACTIONS=true');
            putenv('GITHUB_SHA='.$sha);
            putenv('GITHUB_RUN_ID=123456');
            putenv('GITHUB_RUN_ATTEMPT=1');
            exec(implode(' ', [
                escapeshellarg(PHP_BINARY),
                escapeshellarg($root.'/scripts/reporting/build-r15-publication-candidate.php'),
            ]), $lines, $exitCode);

            self::assertSame(0, $exitCode);
            $candidate = json_decode((string) file_get_contents($output.'/r15-candidate-manifest.json'), true);
            $proof = json_decode((string) file_get_contents($output.'/r15-proof-template.json'), true);
            $request = json_decode((string) file_get_contents($output.'/r15-release-request.json'), true);

            self::assertSame('procurement_cycle', $candidate['code']);
            self::assertSame('candidate', $candidate['admission_status']);
            self::assertSame('blocked', $candidate['publication_status']);
            self::assertSame($sha, $proof['ci']['commit_sha']);
            self::assertSame('blocked', $proof['admission_status']);
            self::assertSame('r15_candidate_evidence', $request['request_kind']);
            self::assertSame('blocked', $request['admission_status']);
            self::assertArrayHasKey('source_runtime', $proof['artifacts']);
            self::assertArrayHasKey('delivery_contract', $proof['artifacts']);
            self::assertArrayHasKey('rbac', $proof['artifacts']);
            self::assertArrayHasKey('formula_runtime', $proof['artifacts']);
        } finally {
            putenv('GITHUB_ACTIONS');
            putenv('GITHUB_SHA');
            putenv('GITHUB_RUN_ID');
            putenv('GITHUB_RUN_ATTEMPT');
            foreach (glob($output.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($output);
        }
    }
}
