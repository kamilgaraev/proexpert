<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use PHPUnit\Framework\TestCase;

final class ProcurementCycleCandidateEvidenceBuilderTest extends TestCase
{
    public function test_builder_rejects_local_environment_spoofing_without_endpoint_backed_oidc(): void
    {
        $root = dirname(__DIR__, 5);
        $sha = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
        foreach ([
            'GITHUB_ACTIONS=true', 'GITHUB_SHA='.$sha, 'GITHUB_RUN_ID=123456', 'GITHUB_RUN_ATTEMPT=1',
            'ACTIONS_ID_TOKEN_REQUEST_URL=https://example.test/token', 'ACTIONS_ID_TOKEN_REQUEST_TOKEN=spoofed',
        ] as $setting) {
            putenv($setting);
        }
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/reporting/build-r15-publication-candidate.php'), $lines, $exitCode);
            self::assertSame(1, $exitCode);
        } finally {
            foreach (['GITHUB_ACTIONS', 'GITHUB_SHA', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'ACTIONS_ID_TOKEN_REQUEST_URL', 'ACTIONS_ID_TOKEN_REQUEST_TOKEN'] as $name) {
                putenv($name);
            }
        }
    }

    public function test_ci_workflow_uses_only_fixed_builder_arguments_output_and_oidc_permission(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 5).'/.github/workflows/notification-concurrency.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString('id-token: write', $workflow);
        self::assertStringContainsString("if: github.event_name == 'push' && github.ref == 'refs/heads/main'", $workflow);
        self::assertStringContainsString('php scripts/reporting/build-r15-publication-candidate.php', $workflow);
        self::assertStringContainsString('path: build/reports/r15-candidate-evidence/', $workflow);
    }
}
