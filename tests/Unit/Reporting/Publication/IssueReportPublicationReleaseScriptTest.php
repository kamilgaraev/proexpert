<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use PHPUnit\Framework\TestCase;

final class IssueReportPublicationReleaseScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-script-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_missing_trusted_root_fails_before_release_resolution(): void
    {
        [$status, $stdout, $stderr] = $this->runScript(null, $this->root);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('report_publication_release_trusted_root_missing', $stderr);
    }

    public function test_incomplete_trusted_root_fails_closed(): void
    {
        [$status, $stdout, $stderr] = $this->runScript($this->root, $this->root);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('report_publication_release_trusted_root_incomplete', $stderr);
    }

    public function test_request_identity_mismatch_fails_before_factory_resolution(): void
    {
        foreach ([
            'r15-candidate-manifest.json',
            'r15-conformance-evidence.json',
            'r15-proof-template.json',
        ] as $name) {
            file_put_contents($this->root.DIRECTORY_SEPARATOR.$name, '{}');
        }
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'r15_release_request.json', $this->request('r15_release_request', str_repeat('a', 40)));
        $external = $this->root.DIRECTORY_SEPARATOR.'other_request.json';
        file_put_contents($external, $this->request('other_request', str_repeat('b', 40)));

        [$status, $stdout, $stderr] = $this->runScript($this->root, $external);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('report_publication_release_request_identity_mismatch', $stderr);
    }

    private function runScript(?string $trustedRoot, string $request): array
    {
        $output = $this->root.DIRECTORY_SEPARATOR.'output';
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'issue-report-publication-release.php')
            .' --request='.escapeshellarg($request)
            .' --output-directory='.escapeshellarg($output);
        $environment = $_ENV;
        unset($environment['MOST_R15_RELEASE_TRUSTED_ROOT']);
        if ($trustedRoot !== null) {
            $environment['MOST_R15_RELEASE_TRUSTED_ROOT'] = $trustedRoot;
        }
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function request(string $requestId, string $commitSha): string
    {
        return json_encode([
            'request_id' => $requestId,
            'schema_version' => '1.0.0',
            'code' => 'procurement_cycle',
            'commit_sha' => $commitSha,
            'proof_sha256' => str_repeat('c', 64),
            'artifact_paths' => [
                'candidate_manifest' => 'r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) && ! is_link($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
