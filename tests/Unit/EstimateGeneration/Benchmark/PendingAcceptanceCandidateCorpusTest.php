<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PendingAcceptanceCandidateCorpusTest extends TestCase
{
    #[Test]
    public function six_new_sources_are_hash_linked_but_gate_remains_disabled_until_owner_approval(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/acceptance-candidate/independent-v1';
        $manifestJson = file_get_contents($root.'/manifest.json');
        $payload = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
        $manifestPayload = $payload;
        unset($manifestPayload['owner_approval']);
        $manifest = BenchmarkManifest::fromArray($manifestPayload, $root, hash('sha256', $manifestJson), false);
        $approval = json_decode(file_get_contents($root.'/owner-approval.json'), true, 32, JSON_THROW_ON_ERROR);
        $envelopes = json_decode(file_get_contents($root.'/recorded-envelopes.json'), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(6, $manifest->caseCount());
        self::assertSame('pending_owner_approval', $approval['status']);
        self::assertFalse($approval['gate_execution_allowed']);
        self::assertSame('pending_owner_approval', $payload['owner_approval']['status']);
        self::assertFalse($payload['owner_approval']['gate_execution_allowed']);
        self::assertSame(hash_file('sha256', $root.'/owner-approval.json'), $payload['owner_approval']['approval_sha256']);
        self::assertNull($approval['approval_ref']);
        self::assertSame('pending_owner_approval', $envelopes['status']);
        self::assertCount(6, $envelopes['envelopes']);

        foreach ($payload['cases'] as $case) {
            $input = $this->local($root, $case['input_locator']);
            $expected = $this->local($root, $case['expected_locator']);
            self::assertFileExists($input);
            self::assertFileExists($expected);
            self::assertSame($case['input_sha256'], hash_file('sha256', $input));
            self::assertSame($case['expected_sha256'], hash_file('sha256', $expected));
            self::assertNotSame($case['input_sha256'], hash('sha256', file_get_contents($input).'mutation'));
            $slug = basename(dirname($input));
            $prediction = $root.'/cases/'.$slug.'/recorded-prediction.json';
            self::assertFileExists($prediction);
            self::assertNotSame(file_get_contents($expected), file_get_contents($prediction));
        }
        self::assertStringStartsWith('AC1015', file_get_contents($root.'/cases/real-dwg/input.dwg'));
        self::assertStringStartsWith('%PDF-', file_get_contents($root.'/cases/scanned-pdf/input.pdf'));
        self::assertStringStartsWith('%PDF-', file_get_contents($root.'/cases/vector-pdf/input.pdf'));
    }

    private function local(string $root, string $locator): string
    {
        $marker = '/cases/';
        $position = strpos($locator, $marker);
        self::assertNotFalse($position);

        return $root.substr($locator, $position);
    }
}
