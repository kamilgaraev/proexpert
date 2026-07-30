<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class PlanOneBEvidenceBuilderTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory);
        }
    }

    public function test_rereads_real_gate_artifacts_and_publishes_only_validated_canonical_bytes(): void
    {
        $fixture = $this->fixture();
        $directory = $this->temporaryDirectory();
        $artifact = $directory.'/plan-1b-completion.json';
        file_put_contents($artifact, 'stale');
        $checks = $this->checks($fixture, $directory);

        $document = (new PlanOneBEvidenceBuilder($artifact))->build(
            $this->reference($fixture),
            $checks,
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        self::assertSame('ci', $document['evidence_scope']);
        self::assertSame($checks['repository_revision'], $document['repository_revision']);
        foreach ($checks['gate_artifacts'] as $index => $input) {
            self::assertSame($input['sha256'], $document['gates'][$index]['artifacts'][0]['sha256']);
        }
        self::assertSame(CanonicalJson::encode($document)."\n", file_get_contents($artifact));
        (new PlanOneBEvidenceValidator($this->reference($fixture)))->validate($document);
        self::assertSame([], glob($directory.'/.plan-1b-evidence-*') ?: []);
    }

    public function test_rejects_expected_digest_or_embedded_revision_mismatch_before_publication(): void
    {
        $fixture = $this->fixture();

        $digestDirectory = $this->temporaryDirectory();
        $digestArtifact = $digestDirectory.'/completion.json';
        $digestChecks = $this->checks($fixture, $digestDirectory);
        $digestChecks['gate_artifacts'][0]['sha256'] = str_repeat('f', 64);
        $this->assertInvalid(
            fn () => (new PlanOneBEvidenceBuilder($digestArtifact))->build(
                $this->reference($fixture),
                $digestChecks,
                new DateTimeImmutable($fixture['generated_at']),
            ),
        );
        self::assertFileDoesNotExist($digestArtifact);

        $revisionDirectory = $this->temporaryDirectory();
        $revisionArtifact = $revisionDirectory.'/completion.json';
        $revisionChecks = $this->checks(
            $fixture,
            $revisionDirectory,
            static function (int $index, array &$envelope): void {
                if ($index === 0) {
                    $envelope['repository_revision'] = str_repeat('f', 40);
                }
            },
        );
        $this->assertInvalid(
            fn () => (new PlanOneBEvidenceBuilder($revisionArtifact))->build(
                $this->reference($fixture),
                $revisionChecks,
                new DateTimeImmutable($fixture['generated_at']),
            ),
        );
        self::assertFileDoesNotExist($revisionArtifact);
    }

    public function test_invalid_gate_artifact_fails_before_atomic_rename(): void
    {
        $fixture = $this->fixture();
        $directory = $this->temporaryDirectory();
        $artifact = $directory.'/completion.json';
        $checks = $this->checks(
            $fixture,
            $directory,
            static function (int $index, array &$envelope): void {
                if ($index === 4) {
                    $envelope['gate']['command'] = 'vendor/bin/phpunit';
                }
            },
        );

        $this->assertInvalid(
            fn () => (new PlanOneBEvidenceBuilder($artifact))->build(
                $this->reference($fixture),
                $checks,
                new DateTimeImmutable($fixture['generated_at']),
            ),
        );

        self::assertFileDoesNotExist($artifact);
        self::assertSame([], glob($directory.'/.plan-1b-evidence-*') ?: []);
    }

    public function test_final_mismatch_is_removed_and_never_left_published(): void
    {
        $fixture = $this->fixture();
        $directory = $this->temporaryDirectory();
        $artifact = $directory.'/completion.json';
        $checks = $this->checks($fixture, $directory);
        $tamperingRename = static function (string $temporary, string $final): bool {
            if (! rename($temporary, $final)) {
                return false;
            }

            return file_put_contents($final, "{\"tampered\":true}\n") !== false;
        };

        try {
            (new PlanOneBEvidenceBuilder($artifact, $tamperingRename))->build(
                $this->reference($fixture),
                $checks,
                new DateTimeImmutable($fixture['generated_at']),
            );
            self::fail('Expected final mismatch.');
        } catch (RuntimeException $exception) {
            self::assertSame('plan_one_b_evidence_artifact_final_mismatch', $exception->getMessage());
        }

        self::assertFileDoesNotExist($artifact);
        self::assertSame([], glob($directory.'/.plan-1b-evidence-*') ?: []);
    }

    private function checks(array $fixture, string $directory, ?callable $mutation = null): array
    {
        $artifacts = [];
        foreach ($fixture['gates'] as $index => $gate) {
            $artifact = $gate['artifacts'][0];
            unset($gate['artifacts']);
            $envelope = [
                'schema_version' => '1.0.0',
                'artifact_id' => $artifact['id'],
                'artifact_type' => $artifact['type'],
                'repository_revision' => $fixture['repository_revision'],
                'gate' => $gate,
            ];
            if ($mutation !== null) {
                $mutation($index, $envelope);
            }
            $path = $directory.'/gate-'.$index.'.json';
            $bytes = CanonicalJson::encode($envelope)."\n";
            file_put_contents($path, $bytes);
            $artifacts[] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        }

        return [
            'repository_revision' => $fixture['repository_revision'],
            'gate_artifacts' => $artifacts,
            'ownership' => $fixture['ownership'],
            'unresolved_risks' => $fixture['unresolved_risks'],
        ];
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid evidence.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('plan_one_b_evidence_invalid', $exception->getMessage());
        }
    }

    private function reference(array $fixture): PlanOneACompletionRef
    {
        $reference = $fixture['plan_1a_reference'];

        return new PlanOneACompletionRef(
            $reference['lock_sha256'],
            $reference['evidence_sha256'],
            new DateTimeImmutable($reference['generated_at']),
            $reference['status'],
        );
    }

    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Reporting/plan-1b-completion.valid.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/most-plan1b-evidence-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
