<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileAssignmentGuard;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LegalDocumentProfileAssignmentGuardTest extends TestCase
{
    public function test_only_an_unstarted_draft_can_use_an_uninitialized_lifecycle(): void
    {
        $guard = new LegalDocumentProfileAssignmentGuard;
        $initial = ['status' => 'draft', 'lifecycle_status' => null, 'approval_status' => null, 'signature_status' => null];

        self::assertTrue($guard->canAssign(new LegalArchiveDocument($initial)));
        foreach ([
            ['status' => 'active'],
            ['status' => 'archived'],
            ['status' => null],
            ['approval_status' => 'approved'],
            ['approval_status' => 'in_progress'],
            ['signature_status' => 'signed'],
            ['signature_status' => 'pending'],
            ['lifecycle_status' => 'signed'],
            ['lifecycle_status' => 'archived'],
            ['lifecycle_status' => 'active'],
            ['lifecycle_status' => ''],
        ] as $state) {
            self::assertFalse($guard->canAssign(new LegalArchiveDocument([...$initial, ...$state])), json_encode($state, JSON_THROW_ON_ERROR));
        }
    }

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
