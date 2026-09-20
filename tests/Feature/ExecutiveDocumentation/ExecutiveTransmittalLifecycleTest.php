<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveTransmittalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_transmittal_keeps_an_immutable_version_manifest_and_replay_is_idempotent(): void
    {
        Storage::fake('s3');
        [$context, $set, $document, $service] = $this->fixture();

        $v1 = $service->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'file' => UploadedFile::fake()->createWithContent('t1.pdf', 'version-one'),
        ]);
        $service->submit($document->fresh(), $context->user->id, null, $v1->id);
        $service->approve($document->fresh(), $context->user->id, null, $v1->id);
        $first = $service->transmit($set->fresh(), $context->user->id, [
            'transmittal_number' => 'T1',
            'operation_key' => 'transmit-t1',
            'recipient' => ['organization_id' => $context->organization->id, 'name' => 'Заказчик'],
        ])->transmittal;

        self::assertSame($v1->id, $first->manifest['documents'][0]['version_id']);
        self::assertSame($v1->content_hash, $first->manifest['documents'][0]['content_hash']);
        self::assertSame($context->organization->id, $first->manifest['recipient']['organization_id']);

        $replay = $service->transmit($set->fresh(), $context->user->id, [
            'transmittal_number' => 'T1',
            'operation_key' => 'transmit-t1',
            'recipient' => ['organization_id' => $context->organization->id, 'name' => 'Заказчик'],
        ])->transmittal;
        self::assertSame($first->id, $replay->id);

        $v2 = $service->addVersion($document->fresh(), $context->user->id, [
            'expected_version_id' => $v1->id,
            'version_number' => '2.0',
            'file' => UploadedFile::fake()->createWithContent('t2.pdf', 'version-two'),
        ]);
        $service->submit($document->fresh(), $context->user->id, null, $v2->id);
        $service->approve($document->fresh(), $context->user->id, null, $v2->id);
        $second = $service->transmit($set->fresh(), $context->user->id, [
            'transmittal_number' => 'T2',
            'operation_key' => 'transmit-t2',
            'recipient' => ['organization_id' => $context->organization->id, 'name' => 'Заказчик'],
        ])->transmittal;

        self::assertNotSame($first->id, $second->id);
        self::assertSame($v1->id, $first->fresh()->manifest['documents'][0]['version_id']);
        self::assertSame($v2->id, $second->manifest['documents'][0]['version_id']);
        $oldReplay = $service->transmit($set->fresh(), $context->user->id, [
            'transmittal_number' => 'T1', 'operation_key' => 'transmit-t1',
            'recipient' => ['organization_id' => $context->organization->id, 'name' => 'Заказчик'],
        ])->transmittal;
        self::assertSame($first->id, $oldReplay->id);
        self::assertSame($v1->file_url, $oldReplay->manifest['documents'][0]['file_url']);
    }

    public function test_foreign_actor_cannot_transmit_an_approved_set(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $version = $service->addVersion($document, $context->user->id, ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('v1.pdf', 'one')]);
        $service->submit($document->fresh(), $context->user->id, null, $version->id);
        $service->approve($document->fresh(), $context->user->id, null, $version->id);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $service->transmit($set, $foreign->user->id, ['transmittal_number' => 'Foreign', 'operation_key' => 'foreign-transmit']);
    }

    public function test_received_is_distinct_from_accepted_and_same_operation_is_idempotent(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $transmittal = $this->publish($context, $set, $document, $service);
        $customer = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService::class);
        $receive = ['operation_key' => 'receive-1', 'expected_manifest_hash' => $transmittal->manifest_hash];
        $customer->decide($transmittal->id, $context->user->id, 'receive', $receive);
        $receivedAt = $transmittal->fresh()->acknowledged_at->toIso8601String();
        self::assertSame('received', $transmittal->fresh()->status);
        self::assertNull($transmittal->fresh()->decision_at);
        $customer->decide($transmittal->id, $context->user->id, 'receive', $receive);
        self::assertSame($receivedAt, $transmittal->fresh()->acknowledged_at->toIso8601String());
        $customer->decide($transmittal->id, $context->user->id, 'accept', ['operation_key' => 'accept-1', 'expected_manifest_hash' => $transmittal->manifest_hash]);
        self::assertSame('accepted', $transmittal->fresh()->status);
        self::assertNotNull($transmittal->fresh()->decision_at);
    }

    public function test_foreign_customer_cannot_read_download_or_confirm_a_transmittal(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $transmittal = $this->publish($context, $set, $document, $service);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $customer = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService::class);
        self::assertCount(0, $customer->list($foreign->user->id));
        foreach (['read', 'download', 'receive'] as $action) {
            try {
                match ($action) {
                    'read' => $customer->find($transmittal->id, $foreign->user->id),
                    'download' => $customer->download($transmittal->id, $transmittal->manifest['documents'][0]['version_id'], $foreign->user->id),
                    'receive' => $customer->decide($transmittal->id, $foreign->user->id, 'receive', ['operation_key' => 'foreign', 'expected_manifest_hash' => $transmittal->manifest_hash]),
                };
                self::fail('Foreign customer action allowed');
            } catch (\App\Exceptions\BusinessLogicException $exception) { self::assertSame(404, $exception->getCode()); }
        }
        self::assertNull($transmittal->fresh()->acknowledged_at);
    }

    public function test_acceptance_rejects_new_draft_and_preserves_received_manifest(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $transmittal = $this->publish($context, $set, $document, $service);
        $customer = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService::class);
        $customer->decide($transmittal->id, $context->user->id, 'receive', ['operation_key' => 'receive', 'expected_manifest_hash' => $transmittal->manifest_hash]);
        $v1 = $transmittal->manifest['documents'][0]['version_id'];
        $service->addVersion($document->fresh(), $context->user->id, ['version_number' => '2', 'expected_version_id' => $v1, 'file' => UploadedFile::fake()->createWithContent('two.pdf', 'two')]);
        try { $customer->decide($transmittal->id, $context->user->id, 'accept', ['operation_key' => 'accept', 'expected_manifest_hash' => $transmittal->manifest_hash]); self::fail('Changed version accepted'); } catch (\DomainException) {}
        self::assertSame('received', $transmittal->fresh()->status);
        self::assertSame($v1, $transmittal->fresh()->manifest['documents'][0]['version_id']);
    }

    public function test_real_recipient_sees_only_manifest_and_downloads_old_file_after_new_revision(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $recipient = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::query()->findOrFail($set->project_id);
        $project->organizations()->attach($recipient->organization->id, ['role' => 'observer', 'role_new' => 'customer', 'is_active' => true]);
        $recipient->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $transmittal = $this->publish($context, $set, $document, $service);
        self::assertSame($recipient->organization->id, $transmittal->manifest['recipient']['organization_id']);
        $v1 = $transmittal->manifest['documents'][0];
        $v2 = $service->addVersion($document->fresh(), $context->user->id, ['version_number' => '2', 'expected_version_id' => $v1['version_id'], 'file' => UploadedFile::fake()->createWithContent('two.pdf', 'two')]);
        $this->mock(\App\Services\Storage\FileService::class)->shouldReceive('temporaryDownloadUrl')->once()->with($v1['file_url'], 300)->andReturn('https://files.example.test/old-v1');
        $customer = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService::class);
        self::assertCount(1, $customer->list($recipient->user->id));
        self::assertCount(0, $customer->list($context->user->id));
        $view = $customer->present($customer->find($transmittal->id, $recipient->user->id), $recipient->user->id);
        self::assertSame($v1['version_id'], $view['documents'][0]['versions'][0]['id']);
        self::assertArrayNotHasKey('file_url', $view['documents'][0]['versions'][0]);
        self::assertStringNotContainsString($v1['file_url'], json_encode($view));
        self::assertSame('https://files.example.test/old-v1', $customer->download($transmittal->id, $v1['version_id'], $recipient->user->id));
        try { $customer->download($transmittal->id, $v2->id, $recipient->user->id); self::fail('Untransmitted draft leaked'); } catch (\App\Exceptions\BusinessLogicException $exception) { self::assertSame(404, $exception->getCode()); }
        $remarkData = ['transmittal_id' => $transmittal->id, 'version_id' => $v1['version_id'], 'body' => 'Old file remark', 'operation_key' => 'remark-1'];
        $remark = $customer->remark($document->id, $recipient->user->id, $remarkData);
        self::assertSame($remark->id, $customer->remark($document->id, $recipient->user->id, $remarkData)->id);
        try { $customer->remark($document->id, $recipient->user->id, array_replace($remarkData, ['body' => 'Changed'])); self::fail('Remark key changed meaning'); } catch (\DomainException) {}
        self::assertSame($v1['version_id'], $remark->version_id);
        self::assertSame('draft', $document->fresh()->status->value);
    }

    public function test_expected_manifest_membership_rejects_stale_publish_without_new_transmittal(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $version = $service->addVersion($document, $context->user->id, ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('one.pdf', 'one')]);
        $service->submit($document->fresh(), $context->user->id, null, $version->id);
        $service->approve($document->fresh(), $context->user->id, null, $version->id);
        try { $service->transmit($set, $context->user->id, ['transmittal_number' => 'T1', 'operation_key' => 'stale', 'expected_versions' => [['document_id' => $document->id, 'version_id' => $version->id + 1000]]]); self::fail('Stale manifest accepted'); } catch (\DomainException) {}
        self::assertSame(0, \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal::query()->where('document_set_id', $set->id)->count());
        self::assertSame('approved', $document->fresh()->status->value);
    }

    public function test_return_requires_reason_and_operation_reuse_cannot_change_decision(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $transmittal = $this->publish($context, $set, $document, $service);
        $customer = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService::class);
        $data = ['operation_key' => 'receive', 'expected_manifest_hash' => $transmittal->manifest_hash];
        $customer->decide($transmittal->id, $context->user->id, 'receive', $data);
        try { $customer->decide($transmittal->id, $context->user->id, 'return', $data + ['comment' => 'Wrong']); self::fail('Key changed meaning'); } catch (\DomainException) {}
        try { $customer->decide($transmittal->id, $context->user->id, 'return', array_replace($data, ['operation_key' => 'return'])); self::fail('Missing reason accepted'); } catch (\DomainException) {}
        $customer->decide($transmittal->id, $context->user->id, 'return', array_replace($data, ['operation_key' => 'return', 'comment' => 'Fix scheme']));
        self::assertSame('returned', $transmittal->fresh()->status);
        self::assertSame('Fix scheme', $transmittal->fresh()->decision_comment);
        self::assertSame($transmittal->manifest, $transmittal->fresh()->manifest);
    }

    public function test_customer_http_commands_validate_manifest_and_preserve_receipt_without_acceptance(): void
    {
        [$context, $set, $document, $service] = $this->fixture();
        $transmittal = $this->publish($context, $set, $document, $service);
        $token = app(\App\Services\Auth\JwtTokenIssuer::class)->issue($context->user, [
            'guard' => 'api_landing', 'organization_id' => $context->organization->id,
        ]);
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $base = '/api/v1/customer/executive-documentation';
        $this->withHeaders($headers)->getJson($base.'/transmittals')->assertOk()->assertJsonPath('data.0.transmittal.id', $transmittal->id);
        $this->withHeaders($headers)->postJson($base.'/transmittals/'.$transmittal->id.'/receive', [])->assertUnprocessable();
        $data = ['operation_key' => 'http-receive', 'expected_manifest_hash' => $transmittal->manifest_hash];
        $this->withHeaders($headers)->postJson($base.'/transmittals/'.$transmittal->id.'/receive', $data)->assertOk()->assertJsonPath('data.transmittal.status', 'received');
        self::assertNull($transmittal->fresh()->decision_at);
        $this->withHeaders($headers)->postJson($base.'/sets/'.$set->id.'/acknowledge', [])->assertConflict();
        $remark = ['operation_key' => 'http-remark', 'transmittal_id' => $transmittal->id, 'version_id' => $transmittal->manifest['documents'][0]['version_id'], 'body' => 'Уточните схему'];
        $response = $this->withHeaders($headers)->postJson($base.'/documents/'.$document->id.'/remarks', $remark)->assertOk();
        $this->withHeaders($headers)->postJson($base.'/documents/'.$document->id.'/remarks', $remark)->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->withHeaders($headers)->postJson($base.'/transmittals/'.$transmittal->id.'/accept', array_replace($data, ['operation_key' => 'http-accept']))->assertConflict();
    }

    private function publish($context, $set, $document, $service)
    {
        $version = $service->addVersion($document, $context->user->id, ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('one.pdf', 'one')]);
        $service->submit($document->fresh(), $context->user->id, null, $version->id);
        $service->approve($document->fresh(), $context->user->id, null, $version->id);
        return $service->transmit($set->fresh(), $context->user->id, ['transmittal_number' => 'T1', 'operation_key' => 'publish-1'])->transmittal;
    }

    private function fixture(): array
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
            'title' => 'Transmittal test set',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Transmittal test document',
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

        return [$context, $set, $document, app(ExecutiveDocumentationService::class)];
    }
}
