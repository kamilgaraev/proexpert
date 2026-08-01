<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\ProcurementCycleReleaseCandidateResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProcurementCycleReleaseCandidateResolverE2ETest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $source = getenv('MOST_R15_PUBLICATION_FIXTURE_DIR');
        if (! is_string($source) || $source === '' || ! is_dir($source)) {
            self::markTestSkipped('MOST_R15_PUBLICATION_FIXTURE_DIR must point to the output of build-r15-publication-candidate.php.');
        }

        $this->fixture = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r15-resolver-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700, true);
        foreach (['r15-candidate-manifest.json', 'r15-conformance-evidence.json', 'r15-proof-template.json', 'r15_release_request.json'] as $file) {
            $bytes = file_get_contents(rtrim($source, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file);
            self::assertIsString($bytes, $file.' is missing from the generated bundle.');
            file_put_contents($this->fixture.DIRECTORY_SEPARATOR.$file, $bytes);
        }
    }

    protected function tearDown(): void
    {
        if ($this->fixture === '') {
            return;
        }

        foreach (glob($this->fixture.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->fixture);
    }

    public function test_accepts_the_real_builder_output(): void
    {
        $request = $this->request();
        $documents = (new ProcurementCycleReleaseCandidateResolver)->resolve($this->fixture, $request['commit_sha']);

        self::assertSame('procurement_cycle', $documents['r15-candidate-manifest.json']['code']);
        self::assertSame('passed', $documents['r15-conformance-evidence.json']['status']);
        self::assertSame($request['commit_sha'], $documents['r15-proof-template.json']['ci']['commit_sha']);
    }

    public function test_rejects_tampered_conformance_document(): void
    {
        $request = $this->request();
        $conformance = $this->document('r15-conformance-evidence.json');
        $conformance['status'] = 'failed';
        file_put_contents($this->fixture.DIRECTORY_SEPARATOR.'r15-conformance-evidence.json', CanonicalJson::encode($conformance));

        $this->expectException(InvalidArgumentException::class);
        (new ProcurementCycleReleaseCandidateResolver)->resolve($this->fixture, $request['commit_sha']);
    }

    public function test_rejects_missing_release_request(): void
    {
        $request = $this->request();
        unlink($this->fixture.DIRECTORY_SEPARATOR.'r15_release_request.json');

        $this->expectException(InvalidArgumentException::class);
        (new ProcurementCycleReleaseCandidateResolver)->resolve($this->fixture, $request['commit_sha']);
    }

    public function test_rejects_wrong_commit_even_when_bundle_is_intact(): void
    {
        $request = $this->request();
        $wrongCommit = str_repeat($request['commit_sha'][0] === 'a' ? 'b' : 'a', 40);

        $this->expectException(InvalidArgumentException::class);
        (new ProcurementCycleReleaseCandidateResolver)->resolve($this->fixture, $wrongCommit);
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return $this->document('r15_release_request.json');
    }

    /** @return array<string,mixed> */
    private function document(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($this->fixture.DIRECTORY_SEPARATOR.$file), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
