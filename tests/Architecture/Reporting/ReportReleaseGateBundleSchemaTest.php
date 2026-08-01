<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportReleaseGateBundleSchemaTest extends TestCase
{
    public function test_fixture_matches_the_closed_schema(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = $this->json($root.'/docs/reports/contracts/report-release-gate-bundle.schema.json');
        $fixture = $this->json($root.'/tests/Fixtures/Reporting/Quality/report-release-gate-bundle.valid.json');

        self::assertSame('urn:most:reporting:report-release-gate-bundle:v1', $schema->{'$id'});
        self::assertSame('report_release_gate_bundle', $fixture->artifact_id);
        self::assertCount(14, $fixture->gates);
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }
}
