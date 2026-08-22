<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use App\Support\ConstructionJournalDeepLink;
use PHPUnit\Framework\TestCase;

class JournalNotificationContractTest extends TestCase
{
    public function test_entry_deep_link_uses_the_admin_spa_route_contract(): void
    {
        self::assertSame('/journal-entries/34', ConstructionJournalDeepLink::entryPath(12, 34));
    }

    public function test_pending_approver_resolution_uses_explicit_project_context(): void
    {
        $listener = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Listeners/NotifyAboutPendingApprovals.php',
        );

        self::assertStringContainsString('AuthorizationService', $listener);
        self::assertStringContainsString("'organization_id' => (int) \$journal->organization_id", $listener);
        self::assertStringContainsString("'project_id' => (int) \$journal->project_id", $listener);
        self::assertStringNotContainsString("where('current_organization_id'", $listener);
    }

    public function test_every_journal_notification_uses_the_shared_deep_link(): void
    {
        foreach ([
            'JournalEntryPendingApprovalNotification.php',
            'JournalEntryApprovedNotification.php',
            'JournalEntryRejectedNotification.php',
        ] as $file) {
            $source = (string) file_get_contents(
                dirname(__DIR__, 3).'/app/Notifications/Journal/'.$file,
            );

            self::assertStringContainsString('ConstructionJournalDeepLink::entryUrl', $source, $file);
            self::assertStringNotContainsString('/admin/construction-journals/', $source, $file);
        }
    }
}
