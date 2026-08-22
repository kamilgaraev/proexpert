<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use PHPUnit\Framework\TestCase;

class JournalOptionsContractTest extends TestCase
{
    public function test_mobile_options_match_server_scope_and_exclude_draft_estimates(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Services/Mobile/MobileConstructionJournalService.php'
        );

        self::assertStringContainsString("->where('status', 'approved')", $source);
        self::assertMatchesRegularExpression(
            "/WorkType::query\(\).*?where\(function .*?orWhereNull\('organization_id'\)/s",
            $source
        );
        self::assertStringNotContainsString("orWhereJsonContains('project_ids'", $source);
        self::assertStringContainsString("orWhereHas('projects'", $source);
    }
}
