<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportCleanupEvidenceSchemaTest extends TestCase
{
    public function test_cleanup_fixture_matches_the_closed_schema(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = $this->json($root.'/docs/reports/contracts/report-cleanup-evidence.schema.json');
        $fixture = $this->json($root.'/tests/Fixtures/Reporting/Quality/report-cleanup-evidence.valid.json');

        self::assertSame('urn:most:reporting:report-cleanup-evidence:v1', $schema->{'$id'});
        self::assertSame('report_cleanup_evidence', $fixture->artifact_id);
        self::assertCount(6, $fixture->checks);
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }
}
