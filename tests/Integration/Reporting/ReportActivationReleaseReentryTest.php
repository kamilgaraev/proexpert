<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportActivationReleaseReentryTest extends TestCase
{
    public function test_foundation_ledger_does_not_claim_a_completed_catalog_activation(): void
    {
        $ledger = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('report_publication_ledger', $ledger['artifact_id']);
        self::assertNotSame(28, count($ledger['events']));
    }
}

