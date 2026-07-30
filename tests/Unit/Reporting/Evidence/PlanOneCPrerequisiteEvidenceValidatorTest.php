<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPrerequisiteEvidenceValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlanOneCPrerequisiteEvidenceValidatorTest extends TestCase
{
    public function test_accepts_only_the_complete_closed_twenty_seven_descriptor_bundle(): void
    {
        $bundle = (new PlanOneCPrerequisiteEvidenceValidator($this->root()))->validateBundle($this->manifestPath());

        self::assertCount(27, $bundle->artifacts);
        self::assertSame('passed', $bundle->planOneACompletion['status']);
        self::assertSame([20, 20], [
            $bundle->planOneACompletion['ci_http_matrices']['malformed_requests']['cases'],
            $bundle->planOneACompletion['ci_http_matrices']['malformed_requests']['passed'],
        ]);
        self::assertSame('plan-1b:pdf_renderer_budget', $bundle->artifacts[17]->id);
    }

    public function test_rejects_a_malformed_matrix_that_is_not_the_locked_twenty_of_twenty(): void
    {
        $directory = $this->temporaryBundle();
        $completionPath = $directory.'/plan-1a-completion.valid.json';
        $completion = $this->decode($completionPath);
        $completion['ci_http_matrices']['malformed_requests']['cases'] = 16;
        $completion['ci_http_matrices']['malformed_requests']['passed'] = 16;
        $this->encode($completionPath, $completion);
        $this->refreshDescriptorHash($directory.'/artifact-bundle.valid.json', 'plan-1a-completion', $completionPath);

        $this->assertRejected($directory.'/artifact-bundle.valid.json');
    }

    public function test_rejects_any_descriptor_whose_raw_bytes_do_not_match_its_recorded_digest(): void
    {
        $directory = $this->temporaryBundle();
        file_put_contents($directory.'/artifacts/plan-1a-ci-malformed.json', "\n");

        $this->assertRejected($directory.'/artifact-bundle.valid.json');
    }

    private function assertRejected(string $manifestPath): void
    {
        $this->expectException(RuntimeException::class);
        (new PlanOneCPrerequisiteEvidenceValidator($this->root()))->validateBundle($manifestPath);
    }

    private function refreshDescriptorHash(string $manifestPath, string $id, string $artifactPath): void
    {
        $manifest = $this->decode($manifestPath);
        foreach ($manifest['artifacts'] as &$descriptor) {
            if ($descriptor['id'] === $id) {
                $descriptor['sha256'] = hash_file('sha256', $artifactPath);
            }
        }
        unset($descriptor);
        $this->encode($manifestPath, $manifest);
    }

    private function temporaryBundle(): string
    {
        $target = sys_get_temp_dir().'/most-plan-1c-prerequisites-'.bin2hex(random_bytes(8));
        mkdir($target, 0700, true);
        $source = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixturesRoot(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($source as $item) {
            $relative = substr($item->getPathname(), strlen($this->fixturesRoot()) + 1);
            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            if ($item->isDir()) {
                mkdir($destination, 0700, true);
            } else {
                copy($item->getPathname(), $destination);
            }
        }

        return $target;
    }

    private function decode(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function encode(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private function manifestPath(): string
    {
        return $this->fixturesRoot().'/artifact-bundle.valid.json';
    }

    private function fixturesRoot(): string
    {
        return $this->root().'/tests/Fixtures/Reporting/Prerequisites';
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
