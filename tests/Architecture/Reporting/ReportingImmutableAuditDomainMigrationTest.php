<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportingImmutableAuditDomainMigrationTest extends TestCase
{
    public function test_reporting_domain_is_added_with_expand_and_validate_migrations(): void
    {
        $root = dirname(__DIR__, 3).'/database/migrations/';
        $expand = (string) file_get_contents(
            $root.'2026_08_08_000001_expand_immutable_audit_reporting_domain.php',
        );
        $validate = (string) file_get_contents(
            $root.'2026_08_08_000002_validate_immutable_audit_reporting_domain.php',
        );

        self::assertStringContainsString("'reporting'", $expand);
        self::assertStringContainsString('NOT VALID', $expand);
        self::assertStringContainsString('immutable_audit_events_domain_check_v3', $expand);
        self::assertStringContainsString('VALIDATE CONSTRAINT immutable_audit_events_domain_check_v3', $validate);
        self::assertStringContainsString('DROP CONSTRAINT IF EXISTS immutable_audit_events_domain_check', $validate);
        self::assertStringContainsString('RENAME CONSTRAINT immutable_audit_events_domain_check_v3', $validate);
    }
}
