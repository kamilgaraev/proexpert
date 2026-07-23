<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileAssignmentGuard;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LegalDocumentProfileAssignmentGuardTest extends TestCase
{
    public function test_profile_can_be_assigned_only_to_unapproved_draft(): void
    {
        $guard = new LegalDocumentProfileAssignmentGuard;
        $draft = new LegalArchiveDocument([
            'lifecycle_status' => 'draft',
            'approval_status' => 'not_started',
        ]);
        $approvedDraft = new LegalArchiveDocument([
            'lifecycle_status' => 'draft',
            'approval_status' => 'approved',
        ]);

        self::assertTrue($guard->canAssign($draft));
        self::assertFalse($guard->canAssign($approvedDraft));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('profile_correction_not_allowed');

        $guard->assertCanAssign($approvedDraft);
    }
}
