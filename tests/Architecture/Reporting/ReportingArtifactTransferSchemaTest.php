<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportingArtifactTransferSchemaTest extends TestCase
{
    public function test_all_three_closed_transfer_modes_match_the_schema(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = $this->json($root.'/docs/reports/contracts/reporting-artifact-transfer.schema.json');
        $fixtures = ['reporting-artifact-transfer.valid.json' => 'report_catalog_activation_transfer', 'plan-4-admin-evidence-transfer.valid.json' => 'plan4_admin_evidence_transfer', 'report-release-evidence-transfer.valid.json' => 'report_release_evidence_transfer'];

        foreach ($fixtures as $file => $artifactId) {
            $fixture = $this->json($root.'/tests/Fixtures/Reporting/Quality/'.$file);
            self::assertSame($artifactId, $fixture->artifact_id);
            self::assertSame('urn:most:reporting:reporting-artifact-transfer:v1', $schema->{'$id'});
            self::assertSame('artifact_transferred', $fixture->status);
        }
    }

    private function json(string $path): object
    {
        return json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }
}
