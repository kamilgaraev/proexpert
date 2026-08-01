<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestFileLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportPublicationReleaseRequestFileLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-request-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'request_id' => 'release_one',
            'schema_version' => '1.0.0',
            'code' => 'procurement_cycle',
            'commit_sha' => str_repeat('a', 40),
            'proof_sha256' => str_repeat('b', 64),
            'artifact_paths' => [
                'candidate_manifest' => 'r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        ], $overrides);
    }

    public function test_loads_exact_non_executable_request_contract(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        file_put_contents($path, json_encode($this->payload(), JSON_THROW_ON_ERROR));

        $request = (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);

        self::assertSame('release_one', $request->requestId);
        self::assertSame('procurement_cycle', $request->code);
        self::assertSame(str_repeat('a', 40), $request->commitSha);
        self::assertSame('r15-proof-template.json', $request->artifactPaths['proof_template']);
    }

    public function test_rejects_symlink_even_when_target_is_inside_trusted_directory(): void
    {
        $target = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        $link = $this->directory.DIRECTORY_SEPARATOR.'release_two.json';
        file_put_contents($target, json_encode($this->payload(), JSON_THROW_ON_ERROR));
        if (! @symlink($target, $link)) {
            $source = file_get_contents(
                dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Publication/'
                .'ReportPublicationReleaseRequestFileLoader.php',
            );
            self::assertIsString($source);
            self::assertStringContainsString('is_link($requestFile)', $source);

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($link, $this->directory);
    }

    public function test_rejects_filename_and_request_identifier_mismatch(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_two.json';
        file_put_contents($path, json_encode($this->payload(), JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);
    }

    public function test_rejects_extra_request_fields(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        file_put_contents(
            $path,
            json_encode($this->payload(['admission' => 'Tests\\Fixture']), JSON_THROW_ON_ERROR),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);
    }

    public function test_rejects_path_traversal(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        file_put_contents($path, json_encode($this->payload([
            'artifact_paths' => [
                'candidate_manifest' => '../r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        ]), JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);
    }

    public function test_rejects_wrong_code_and_sha_formats(): void
    {
        foreach ([
            ['code' => 'other_cycle'],
            ['commit_sha' => str_repeat('A', 40)],
            ['commit_sha' => str_repeat('a', 39)],
            ['proof_sha256' => str_repeat('c', 63)],
            ['proof_sha256' => str_repeat('C', 64)],
        ] as $override) {
            $path = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
            file_put_contents($path, json_encode($this->payload($override), JSON_THROW_ON_ERROR));

            try {
                (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);
                self::fail('Invalid publication request was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_publication_release_request_invalid', $exception->getMessage());
            }
        }
    }
}
