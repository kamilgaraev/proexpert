<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use Closure;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class LegalWorkflowReplayAccessSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    public function test_submit_replay_requires_current_grant_and_fresh_document_boundaries(): void
    {
        foreach (['revoked', 'confidentiality', 'project', 'permission'] as $change) {
            [$fixture, $actor, $document, $version, $grant, $override] = $this->scenario($change === 'confidentiality' ? 'internal' : 'restricted');
            $service = app(LegalDocumentWorkflowService::class);
            $instance = $service->submit($document, (int) $version->id, $actor, $override);
            self::assertSame($instance->id, $service->submit($document, (int) $version->id, $actor, $override)->id);
            if ($change === 'revoked') {
                $this->revoke($grant, $fixture);
            } elseif ($change === 'confidentiality') {
                DB::table('legal_archive_documents')->where('id', $document->id)->update(['confidentiality_level' => 'secret']);
            } elseif ($change === 'project') {
                $inaccessible = Project::factory()->create(['organization_id' => $fixture->organization->id]);
                AuthorizationContext::getProjectContext($inaccessible->id, $fixture->organization->id);
                DB::table('legal_archive_documents')->where('id', $document->id)->update(['primary_project_id' => $inaccessible->id]);
            } else {
                UserRoleAssignment::query()->where('user_id', $actor->id)->where('is_active', true)->sole()
                    ->forceFill(['is_active' => false])->save();
            }
            $this->assertDeniedWithoutWrites(fn () => $service->submit($document, (int) $version->id, $actor, $override));
        }
    }

    public function test_decision_and_cancel_replay_require_current_view_grant(): void
    {
        foreach (['approve', 'cancel'] as $action) {
            [$fixture, $actor, $document, $version, $grant, $override] = $this->scenario();
            $service = app(LegalDocumentWorkflowService::class);
            $instance = $service->submit($document, (int) $version->id, $actor, $override);
            $step = $instance->steps()->where('status', 'active')->sole();
            $input = new WorkflowDecisionInput($action, (string) Str::uuid(), (int) $instance->lock_version, (int) $step->lock_version, reason: 'Authorized operation');
            $operation = $action === 'cancel'
                ? fn () => $service->cancel($instance, $actor, $input)
                : fn () => $service->decide($step, $actor, $input);
            self::assertSame($action === 'cancel' ? 'cancelled' : 'approved', $operation()->status);
            self::assertSame($instance->id, $operation()->id);
            $this->revoke($grant, $fixture);
            $this->assertDeniedWithoutWrites($operation);
        }
    }

    public function test_submit_collision_fallback_rechecks_access_and_preserves_authorized_replay(): void
    {
        [$fixture, $actor, $document, $version, $grant, $override] = $this->scenario();
        $instance = app(LegalDocumentWorkflowService::class)->submit($document, (int) $version->id, $actor, $override);
        $service = $this->collisionService();
        self::assertSame($instance->id, $service->submit($document, (int) $version->id, $actor, $override)->id);
        $this->revoke($grant, $fixture);
        $service = $this->collisionService();
        $this->assertDeniedWithoutWrites(fn () => $service->submit($document, (int) $version->id, $actor, $override));
    }

    private function scenario(string $level = 'restricted'): array
    {
        Queue::fake([SendNotificationJob::class]);
        $this->enableImmutableAuditWriter();
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry']);
        $actor = $fixture->addMember([], [LegalWorkflowPermissions::SUBMIT, LegalWorkflowPermissions::APPROVE, LegalWorkflowPermissions::CANCEL]);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        AuthorizationContext::getProjectContext($project->id, $fixture->organization->id);
        $project->users()->attach($actor->id, ['is_active' => true]);
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $fixture->organization->id, 'primary_project_id' => $project->id,
            'title' => 'Workflow replay access', 'document_type' => 'contract', 'status' => 'draft',
            'type_profile_code' => 'contract.work', 'lifecycle_status' => 'draft',
            'approval_status' => 'not_started', 'signature_status' => 'unsigned',
            'confidentiality_level' => $level, 'lock_version' => 0,
        ]);
        $file = LegalArchiveDocumentFile::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'role' => 'primary', 'title' => 'Primary', 'sort_order' => 0, 'is_required' => true,
        ]);
        $version = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'document_file_id' => $file->id, 'version_number' => 1, 'is_current' => true,
            'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-'.$fixture->organization->id.'/security/replay.pdf',
            'original_filename' => 'replay.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 12,
            'content_hash' => hash('sha256', 'replay-document'),
            'uploaded_by_user_id' => $fixture->owner->id, 'uploaded_at' => now(),
        ]);
        $file->forceFill(['current_version_id' => $version->id])->save();
        $document->forceFill(['current_primary_version_id' => $version->id])->save();
        $grant = $level === 'internal' ? null : LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $actor->id, 'abilities' => ['view'], 'granted_by_user_id' => $fixture->owner->id,
        ]);
        $template = app(LegalWorkflowTemplateService::class)->createVersion(
            (int) $fixture->organization->id, 'contract', 'Workflow replay', [[
                'key' => 'review', 'label' => 'Review', 'sequence' => 10, 'parallel_group' => 'review',
                'required' => true, 'policy_key' => 'legal_review', 'actor_type' => 'user', 'actor_reference' => (string) $actor->id,
            ]], $fixture->owner,
        );

        return [$fixture, $actor, $document->refresh(), $version, $grant,
            new WorkflowOverride(idempotencyKey: (string) Str::uuid(), templateId: (int) $template->id)];
    }

    private function revoke(?LegalDocumentAccessGrant $grant, AssistantRealAuthorizationFixture $fixture): void
    {
        self::assertInstanceOf(LegalDocumentAccessGrant::class, $grant);
        $grant->forceFill([
            'revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id,
            'revocation_reason' => 'Access revoked before replay',
        ])->save();
    }

    private function collisionService(): LegalDocumentWorkflowService
    {
        $real = DB::connection();
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('transaction')->once()->ordered()->andThrow(new QueryException(
            $real->getName(), 'insert workflow collision', [], new PDOException('Simulated unique collision', 23505),
        ));
        $connection->shouldReceive('transaction')->once()->ordered()->andReturnUsing(
            fn (Closure $callback, int $attempts) => $real->transaction($callback, $attempts),
        );
        foreach (['getName', 'getDriverName', 'transactionLevel', 'select'] as $method) {
            $connection->shouldReceive($method)->andReturnUsing(fn (...$arguments) => $real->$method(...$arguments));
        }

        return app()->make(LegalDocumentWorkflowService::class, ['connection' => $connection]);
    }

    private function assertDeniedWithoutWrites(Closure $operation): void
    {
        $before = $this->snapshot();
        try {
            $operation();
            self::fail('Workflow replay returned protected data after access was removed');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_access_denied', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['legal_archive_documents', 'legal_workflow_instances', 'legal_workflow_steps', 'legal_workflow_decisions', 'immutable_audit_events', 'legal_document_outbox', 'legal_document_notification_deliveries'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }
}
