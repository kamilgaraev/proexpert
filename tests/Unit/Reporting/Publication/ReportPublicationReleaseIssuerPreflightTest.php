<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistryFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseIssuerPreflight;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestFileLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportPublicationReleaseIssuerPreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-preflight-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_accepts_the_r15_profile_documents_from_its_canonical_request_path(): void
    {
        $this->documents();

        $input = $this->preflight()->validate(
            $this->root.DIRECTORY_SEPARATOR.'r15_release_request.json',
            $this->root,
        );

        self::assertSame('procurement_cycle', $input->profile->code);
        self::assertSame('r15_release_request', $input->request->requestId);
        self::assertSame(realpath($this->root), $input->trustedRoot);
        self::assertSame([
            'candidate_manifest',
            'conformance_evidence',
            'proof_template',
        ], array_keys($input->artifactFiles));
    }

    public function test_rejects_a_request_not_named_by_its_profile_before_runtime_composition(): void
    {
        $this->documents();
        copy(
            $this->root.DIRECTORY_SEPARATOR.'r15_release_request.json',
            $this->root.DIRECTORY_SEPARATOR.'different_request.json',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');

        $this->preflight()->validate($this->root.DIRECTORY_SEPARATOR.'different_request.json', $this->root);
    }

    public function test_rejects_a_symlinked_profile_artifact_before_runtime_composition(): void
    {
        $this->documents();
        $artifact = $this->root.DIRECTORY_SEPARATOR.'r15-candidate-manifest.json';
        unlink($artifact);
        if (! @symlink($this->root.DIRECTORY_SEPARATOR.'r15-proof-template.json', $artifact)) {
            $source = file_get_contents(
                dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Publication/'
                .'ReportPublicationReleaseIssuerPreflight.php',
            );
            self::assertIsString($source);
            self::assertStringContainsString('is_link($path)', $source);

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_trusted_root_incomplete');

        $this->preflight()->validate($this->root.DIRECTORY_SEPARATOR.'r15_release_request.json', $this->root);
    }

    private function preflight(): ReportPublicationReleaseIssuerPreflight
    {
        return new ReportPublicationReleaseIssuerPreflight(
            new ReportPublicationReleaseRequestFileLoader,
            ProjectReportPublicationReleaseRequestRegistryFactory::profiles(),
        );
    }

    private function documents(): void
    {
        foreach ([
            'r15-candidate-manifest.json' => '{}',
            'r15-conformance-evidence.json' => '{}',
            'r15-proof-template.json' => '{}',
            'r15_release_request.json' => json_encode([
                'request_id' => 'r15_release_request',
                'schema_version' => '1.0.0',
                'code' => 'procurement_cycle',
                'commit_sha' => str_repeat('a', 40),
                'proof_sha256' => str_repeat('b', 64),
                'artifact_paths' => [
                    'candidate_manifest' => 'r15-candidate-manifest.json',
                    'conformance_evidence' => 'r15-conformance-evidence.json',
                    'proof_template' => 'r15-proof-template.json',
                ],
            ], JSON_THROW_ON_ERROR),
        ] as $name => $contents) {
            file_put_contents($this->root.DIRECTORY_SEPARATOR.$name, $contents);
        }
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
