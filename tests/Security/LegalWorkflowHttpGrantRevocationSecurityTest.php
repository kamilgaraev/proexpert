<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowDecision;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Enums\AuthSessionStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class LegalWorkflowHttpGrantRevocationSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    public function test_http_access_revocation_denies_metadata_and_decision_without_domain_writes(): void
    {
        Queue::fake([SendNotificationJob::class]);
        $this->enableImmutableAuditWriter();
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry']);
        $actor = $fixture->addMember([], [
            'admin.access', LegalWorkflowPermissions::VIEW, LegalWorkflowPermissions::APPROVE,
        ]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        AuthorizationContext::getProjectContext($project->id, $fixture->organization->id);
        $project->users()->attach($actor->id, ['is_active' => true]);
        [$document, $version] = $this->document($fixture, $project);
        $actorGrant = null;
        foreach ([$fixture->owner, $actor] as $subject) {
            $grant = LegalDocumentAccessGrant::query()->create([
                'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
                'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
                'subject_user_id' => $subject->id,
                'abilities' => $subject->is($actor) ? ['view'] : ['view', 'manage'],
                'granted_by_user_id' => $fixture->owner->id,
            ]);
            if ($subject->is($actor)) {
                $actorGrant = $grant;
            }
        }
        self::assertInstanceOf(LegalDocumentAccessGrant::class, $actorGrant);
        $template = app(LegalWorkflowTemplateService::class)->createVersion(
            (int) $fixture->organization->id, 'contract', 'Grant revocation workflow', [[
                'key' => 'review', 'label' => 'Review', 'sequence' => 10, 'parallel_group' => 'review',
                'required' => true, 'policy_key' => 'legal_review', 'actor_type' => 'user',
                'actor_reference' => (string) $actor->id,
            ]], $fixture->owner,
        );
        $instance = app(LegalDocumentWorkflowService::class)->submit(
            $document->fresh(), (int) $version->id, $fixture->owner,
            new WorkflowOverride(idempotencyKey: (string) Str::uuid(), templateId: (int) $template->id),
        );
        $step = $instance->steps()->where('status', 'active')->sole();
        $actorContext = $this->context($fixture, $actor);
        $ownerContext = $this->context($fixture, $fixture->owner);
        $base = '/api/v1/admin/legal-archive/';
        $actionsUrl = $base.'documents/'.$document->id.'/available-actions';
        $this->getJson($actionsUrl, $actorContext->authHeaders())->assertOk();
        self::assertFalse($actor->hasPermission('legal_archive.view', ['organization_id' => $fixture->organization->id]));

        $this->postJson($base.'access-grants/'.$actorGrant->id.'/revoke', [
            'lock_version' => (int) $document->fresh()->lock_version,
            'reason' => 'Access revoked before decision',
        ], $ownerContext->authHeaders())->assertOk();
        self::assertNotNull($actorGrant->fresh()->revoked_at);
        $before = $this->snapshot($document, $instance);

        $this->getJson($actionsUrl, $actorContext->authHeaders())->assertStatus(409)->assertJsonMissingPath('data.workflow_summary');
        $this->postJson($base.'workflow-steps/'.$step->id.'/approve', [
            'idempotency_key' => (string) Str::uuid(),
            'instance_lock_version' => (int) $instance->lock_version,
            'step_lock_version' => (int) $step->lock_version,
        ], $actorContext->authHeaders())->assertStatus(409);
        self::assertSame($before, $this->snapshot($document, $instance));
    }

    private function document(AssistantRealAuthorizationFixture $fixture, Project $project): array
    {
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $fixture->organization->id, 'primary_project_id' => $project->id,
            'title' => 'Restricted HTTP workflow', 'document_type' => 'contract', 'status' => 'draft',
            'type_profile_code' => 'contract.work', 'lifecycle_status' => 'draft',
            'approval_status' => 'not_started', 'signature_status' => 'unsigned',
            'confidentiality_level' => 'restricted', 'lock_version' => 0,
        ]);
        $file = LegalArchiveDocumentFile::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'role' => 'primary', 'title' => 'Primary', 'sort_order' => 0, 'is_required' => true,
        ]);
        $version = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'document_file_id' => $file->id, 'version_number' => 1, 'is_current' => true,
            'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-'.$fixture->organization->id.'/security/workflow.pdf',
            'original_filename' => 'workflow.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 12,
            'content_hash' => hash('sha256', 'workflow-document'),
            'uploaded_by_user_id' => $fixture->owner->id, 'uploaded_at' => now(),
        ]);
        $file->forceFill(['current_version_id' => $version->id])->save();
        $document->forceFill(['current_primary_version_id' => $version->id])->save();

        return [$document->refresh(), $version];
    }

    private function context(AssistantRealAuthorizationFixture $fixture, User $user): AdminApiTestContext
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id, 'organization_id' => $fixture->organization->id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Legal workflow security test', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $token = app(WebAuthTokenService::class)->issue($user, 'admin', $sessionUuid, (int) $fixture->organization->id, false);

        return new AdminApiTestContext($fixture->organization, $user, $token->accessToken);
    }

    private function snapshot(LegalArchiveDocument $document, LegalWorkflowInstance $instance): array
    {
        return [
            'document' => $document->fresh()->getRawOriginal(),
            'instance' => $instance->fresh()->getRawOriginal(),
            'steps' => LegalWorkflowStep::query()->where('instance_id', $instance->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'decisions' => LegalWorkflowDecision::query()->where('document_id', $document->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'audit' => ImmutableAuditEvent::query()->where('organization_id', $document->organization_id)->where('domain', 'legal_archive')->orderBy('id')->get()->map->getRawOriginal()->all(),
            'outbox' => LegalDocumentOutboxMessage::query()->where('organization_id', $document->organization_id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'deliveries' => LegalDocumentNotificationDelivery::query()->where('document_id', $document->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }
}
