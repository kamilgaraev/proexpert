<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\Models\Project;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Exceptions\BusinessLogicException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentReviewCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_review_another_persons_answer_with_independent_history(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remarks = collect(['one', 'two', 'three'])->map(fn ($body) => $service->addRemark($document->fresh(), $context->user->id, ['body' => $body, 'version_id' => $v1->id]));
        $first = $remarks[0];
        $service->answerRemark($first, $worker->id, 'First answer', $v1->id);
        self::assertNotNull($first->fresh()->answered_at);
        $service->reviewRemark($first->fresh(), $context->user->id, 'return', 'Provide evidence', $v1->id);
        $service->answerRemark($first->fresh(), $worker->id, 'Second answer', $v1->id);
        $service->reviewRemark($first->fresh(), $context->user->id, 'accept', 'Checked', $v1->id);
        self::assertCount(4, $first->fresh()->metadata['review_history']);
        self::assertSame('First answer', $first->fresh()->metadata['review_history'][0]['comment']);
        self::assertSame(2, $document->openRemarks()->count());
        try { $service->approve($document->fresh(), $context->user->id, null, $v1->id); self::fail('Other open remarks must block approval'); } catch (\DomainException) {}
        foreach ($remarks->skip(1) as $remark) {
            $service->answerRemark($remark, $worker->id, 'Explained', $v1->id);
            $service->reviewRemark($remark->fresh(), $context->user->id, 'accept', 'Checked', $v1->id);
        }
        $service->approve($document->fresh(), $context->user->id, null, $v1->id);
        self::assertSame('approved', $v1->fresh()->status);
    }

    public function test_answer_author_cannot_accept_own_response(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Problem', 'version_id' => $v1->id]);
        $service->answerRemark($remark, $worker->id, 'Done', $v1->id);
        $this->expectException(\DomainException::class);
        $service->reviewRemark($remark->fresh(), $worker->id, 'accept', 'My work', $v1->id);
    }

    public function test_foreign_actor_cannot_run_review_commands(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Problem', 'version_id' => $v1->id]);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        foreach (['remark', 'answer', 'review', 'submit', 'approve'] as $action) {
            try {
                match ($action) {
                    'remark' => $service->addRemark($document->fresh(), $foreign->user->id, ['body' => 'Foreign']),
                    'answer' => $service->answerRemark($remark->fresh(), $foreign->user->id, 'Foreign', $v1->id),
                    'review' => $service->reviewRemark($remark->fresh(), $foreign->user->id, 'accept', 'Foreign', $v1->id),
                    'submit' => $service->submit($document->fresh(), $foreign->user->id, null, $v1->id),
                    'approve' => $service->approve($document->fresh(), $foreign->user->id, null, $v1->id),
                };
                self::fail('Foreign action allowed: '.$action);
            } catch (BusinessLogicException $exception) { self::assertSame(404, $exception->getCode()); }
        }
        self::assertSame('open', $remark->fresh()->status->value);
    }

    public function test_new_revision_does_not_hide_unresolved_old_remarks(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $v1 = $this->present($document, $service, $context->user->id);
        $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Blocking issue', 'severity' => 'critical', 'version_id' => $v1->id]);
        $v2 = $service->addVersion($document->fresh(), $context->user->id, ['version_number' => '2', 'expected_version_id' => $v1->id, 'file' => UploadedFile::fake()->createWithContent('two.pdf', 'two')]);
        $service->submit($document->fresh(), $context->user->id, null, $v2->id);
        $this->expectException(\DomainException::class);
        $service->approve($document->fresh(), $context->user->id, null, $v2->id);
    }

    public function test_return_requires_reason_and_preserves_presented_file(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $v1 = $this->present($document, $service, $context->user->id);
        $file = $v1->file_url;
        $hash = $v1->content_hash;
        $service->reject($document->fresh(), $context->user->id, 'Correct drawing', $v1->id);
        self::assertSame('rejected', $v1->fresh()->status);
        self::assertSame($file, $v1->fresh()->file_url);
        self::assertSame($hash, $v1->fresh()->content_hash);
        self::assertSame('Correct drawing', $v1->fresh()->metadata['review_history'][0]['comment']);
        $this->expectException(\DomainException::class);
        $service->submit($document->fresh(), $context->user->id, null, $v1->id);
    }

    public function test_old_remark_resolution_preserves_current_draft_and_records_correction_version(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Wrong drawing', 'version_id' => $v1->id]);
        $v2 = $service->addVersion($document->fresh(), $context->user->id, ['version_number' => '2', 'expected_version_id' => $v1->id, 'file' => UploadedFile::fake()->createWithContent('two.pdf', 'two')]);
        $service->answerRemark($remark, $worker->id, 'Corrected drawing', $v1->id, $v2->id);
        $service->reviewRemark($remark->fresh(), $context->user->id, 'accept', 'Checked v2', $v1->id);
        self::assertSame('draft', $document->fresh()->status->value);
        self::assertSame('draft', $v2->fresh()->status);
        self::assertSame($v1->id, $remark->fresh()->version_id);
        self::assertSame($v2->id, $remark->fresh()->metadata['response_version_id']);
        self::assertSame('under_review', $v1->fresh()->status);
    }

    public function test_http_return_requires_reason_and_preserves_version(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $v1 = $this->present($document, $service, $context->user->id);
        $url = '/api/v1/admin/executive-documentation/documents/'.$document->id.'/reject';
        $this->postJson($url, ['version_id' => $v1->id], $context->authHeaders())->assertStatus(422);
        $this->postJson($url, ['version_id' => $v1->id, 'comment' => 'Fix drawing'], $context->authHeaders())->assertOk()->assertJsonPath('data.status', 'rejected');
        self::assertSame('Fix drawing', $v1->fresh()->metadata['review_history'][0]['comment']);
    }

    public function test_foreign_response_version_is_rejected_without_changing_answer(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Problem', 'version_id' => $v1->id]);
        try { $service->answerRemark($remark, $worker->id, 'Fixed elsewhere', $v1->id, $v1->id + 1000); self::fail('Foreign version allowed'); } catch (\DomainException) {}
        self::assertSame('open', $remark->fresh()->status->value);
        self::assertNull($remark->fresh()->response);
    }

    public function test_stale_review_cannot_accept_a_later_answer_in_the_same_version(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Problem', 'version_id' => $v1->id]);
        $service->answerRemark($remark, $worker->id, 'First', $v1->id);
        $service->reviewRemark($remark->fresh(), $context->user->id, 'return', 'Retry', $v1->id);
        $service->answerRemark($remark->fresh(), $worker->id, 'Second', $v1->id);
        $this->expectException(\DomainException::class);
        $service->reviewRemark($remark->fresh(), $context->user->id, 'accept', 'Stale decision', $v1->id, 1);
    }

    public function test_original_file_is_not_reported_as_a_corrected_revision(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Problem', 'version_id' => $v1->id]);
        $this->expectException(\DomainException::class);
        $service->answerRemark($remark, $worker->id, 'Claims correction', $v1->id, $v1->id);
    }

    public function test_http_remark_review_requires_current_answer_counter(): void
    {
        [$context, $document, $service] = $this->documentFixture();
        $worker = $this->member($context);
        $v1 = $this->present($document, $service, $context->user->id);
        $remark = $service->addRemark($document->fresh(), $context->user->id, ['body' => 'Problem', 'version_id' => $v1->id]);
        $service->answerRemark($remark, $worker->id, 'Explained', $v1->id);
        $url = '/api/v1/admin/executive-documentation/remarks/'.$remark->id.'/review';
        $payload = ['decision' => 'accept', 'comment' => 'Checked', 'expected_version_id' => $v1->id];
        $this->postJson($url, $payload, $context->authHeaders())->assertStatus(422)->assertJsonValidationErrors('expected_revision');
        $this->postJson($url, $payload + ['expected_revision' => 1], $context->authHeaders())->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.status', 'resolved');
        $this->postJson($url, $payload + ['expected_revision' => 1], $context->authHeaders())->assertStatus(409);
        self::assertCount(2, $remark->fresh()->metadata['review_history']);
    }

    private function member(AdminApiTestContext $context): User
    {
        $user = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($user->id, ['is_active' => true, 'is_owner' => false]);
        UserRoleAssignment::assignRole(user: $user, roleSlug: 'organization_owner', context: AuthorizationContext::getOrganizationContext($context->organization->id));
        return $user;
    }

    private function present(ExecutiveDocument $document, ExecutiveDocumentationService $service, int $actor): \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion
    {
        $version = $service->addVersion($document, $actor, ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('one.pdf', 'one')]);
        $service->submit($document->fresh(), $actor, null, $version->id);
        return $version;
    }

    private function documentFixture(): array
    {
        Storage::fake('s3');
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'SET-'.uniqid(),
            'title' => 'Revision test set',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Revision test document',
            'status' => 'draft',
            'profile_data' => [
                'drawing_set_code' => 'RD-1',
                'drawing_section' => 'АР',
                'sheet_list' => ['1'],
                'compliance_mark' => 'Соответствует',
                'responsible_person' => 'Инженер',
                'authority_document' => 'Доверенность',
                'drawing_set_status' => 'review',
            ],
        ]);

        return [$context, $document, app(ExecutiveDocumentationService::class)];
    }
}
