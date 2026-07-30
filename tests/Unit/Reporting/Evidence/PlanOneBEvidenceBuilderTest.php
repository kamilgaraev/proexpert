<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php';
require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php';

final class PlanOneBEvidenceBuilderTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (new FilesystemIterator($directory) as $item) {
                unlink($item->getPathname());
            }
            rmdir($directory);
        }
    }

    public function test_builds_validates_atomically_writes_and_rereads_the_deterministic_fixture(): void
    {
        $fixture = $this->fixture();
        $artifact = $this->artifactPath();
        file_put_contents($artifact, 'stale');

        $document = (new PlanOneBEvidenceBuilder($artifact))->build(
            $this->reference($fixture),
            $this->checks($fixture),
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        self::assertEquals($fixture, $document);
        self::assertSame(CanonicalJson::encode($fixture)."\n", file_get_contents($artifact));
        self::assertSame(
            '53adff3fe58fcb15eb2607ec8124e3f8dfb6bed797caeae65edb431b51edf011',
            hash_file('sha256', $artifact),
        );
        self::assertSame(
            [basename($artifact)],
            array_map(
                static fn (\SplFileInfo $file): string => $file->getFilename(),
                iterator_to_array(new FilesystemIterator(dirname($artifact)), false),
            ),
        );
    }

    public function test_rejects_failed_or_incomplete_checks_before_creating_an_artifact(): void
    {
        $fixture = $this->fixture();
        $checks = $this->checks($fixture);
        $checks['gates'][0]['status'] = 'failed';
        $artifact = $this->artifactPath();

        try {
            (new PlanOneBEvidenceBuilder($artifact))->build(
                $this->reference($fixture),
                $checks,
                new DateTimeImmutable('2026-07-30T12:00:00Z'),
            );
            self::fail('Expected invalid checks.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('plan_one_b_evidence_invalid', $exception->getMessage());
        }

        self::assertFileDoesNotExist($artifact);
    }

    private function checks(array $fixture): array
    {
        return [
            'repository_revision' => $fixture['repository_revision'],
            'gates' => $fixture['gates'],
            'ownership' => $fixture['ownership'],
            'performance_measurements' => $fixture['performance_measurements'],
            'unresolved_risks' => $fixture['unresolved_risks'],
        ];
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

    private function artifactPath(): string
    {
        $directory = sys_get_temp_dir().'/most-plan1b-evidence-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory.'/plan-1b-completion.json';
    }
}
