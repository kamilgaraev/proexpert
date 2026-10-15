<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class LegalWorkflowDocumentAccessSecurityTest extends TestCase
{
    public function test_internal_workflow_requires_current_document_access_and_batch_matches_single(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $actor = $fixture->addMember([], [LegalWorkflowPermissions::APPROVE]);
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $fixture->organization->id,
            'title' => 'Restricted workflow document',
            'document_type' => 'contract', 'status' => 'draft',
            'confidentiality_level' => 'restricted', 'lock_version' => 0,
        ]);
        $grant = LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $actor->id, 'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ]);
        $context = ['organization_id' => $fixture->organization->id];
        self::assertTrue($actor->hasPermission(LegalWorkflowPermissions::APPROVE, $context));
        self::assertFalse($actor->hasPermission('legal_archive.view', $context));
        $access = app(LegalDocumentAccessService::class);
        $workflow = app(LegalWorkflowAuthorization::class);
        $access->authorizePermission($actor, $document, LegalWorkflowPermissions::APPROVE);
        self::assertTrue($workflow->can($actor, $document, LegalWorkflowPermissions::APPROVE));
        self::assertTrue($workflow->forMany($actor, collect([$document]), [LegalWorkflowPermissions::APPROVE])[$document->id][LegalWorkflowPermissions::APPROVE]);

        $grant->forceFill([
            'revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id,
            'revocation_reason' => 'Grant revoked before workflow access',
        ])->save();
        $denied = false;
        try {
            $access->authorizePermission($actor, $document, LegalWorkflowPermissions::APPROVE);
        } catch (AuthorizationException) {
            $denied = true;
        }
        self::assertTrue($denied);
        self::assertFalse($workflow->can($actor, $document, LegalWorkflowPermissions::APPROVE));
        self::assertFalse($workflow->forMany($actor, collect([$document]), [LegalWorkflowPermissions::APPROVE])[$document->id][LegalWorkflowPermissions::APPROVE]);
    }
}
