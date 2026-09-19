<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Resources\Api\V1\Admin\Contract\ContractOrganizationViewResource;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractOrganizationView;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractOrganizationViewService;
use App\Services\Contract\ContractPartySnapshotService;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ContractOrganizationViewTest extends TestCase
{
    public function test_summary_shares_legal_count_but_keeps_accounting_private_and_respects_archive(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'number' => 'SUMMARY-1', 'date' => '2026-09-19', 'status' => 'active',
            'base_amount' => 1000, 'total_amount' => 1000, 'planned_advance_amount' => 200, 'actual_advance_amount' => 100,
        ]);
        $ownerView = ContractOrganizationView::create(['contract_id' => $contract->id, 'organization_id' => $owner->id]);
        $otherView = ContractOrganizationView::create(['contract_id' => $contract->id, 'organization_id' => $other->id]);
        $contract->performanceActs()->create([
            'project_id' => $project->id, 'act_document_number' => 'SUMMARY-ACT',
            'act_date' => '2026-09-19', 'amount' => 300, 'status' => 'draft', 'is_approved' => true,
        ]);
        foreach ([[$owner->id, 150], [$other->id, 999]] as [$organizationId, $paid]) {
            $contract->payments()->create([
                'organization_id' => $organizationId, 'project_id' => $project->id,
                'invoiceable_type' => Contract::class, 'document_number' => 'SUMMARY-PAY-'.$organizationId,
                'document_date' => '2026-09-19', 'document_type' => 'invoice', 'direction' => 'outgoing',
                'invoice_type' => 'act', 'amount' => 1000, 'paid_amount' => $paid, 'currency' => 'RUB', 'status' => 'draft',
            ]);
        }
        $service = app(\App\Services\Contract\ContractService::class);
        $ownerSummary = $service->getContractsSummary($owner->id);
        self::assertSame(1, $ownerSummary['total_contracts']);
        self::assertSame(300.0, $ownerSummary['financial']['total_performed_amount']);
        self::assertSame(150.0, $ownerSummary['financial']['total_paid_amount']);
        self::assertSame(100.0, $ownerSummary['advances']['total_actual']);
        $otherSummary = $service->getContractsSummary($other->id, ['contractor_context' => true, 'related_party_organization_id' => $owner->id]);
        self::assertSame(1, $otherSummary['total_contracts']);
        foreach ($otherSummary['financial'] as $key => $amount) {
            $expected = match ($key) {
                'total_paid_amount' => 999,
                'remaining_to_pay' => -999,
                default => 0,
            };
            self::assertEquals($expected, $amount);
        }
        self::assertSame(0.0, $otherSummary['advances']['total_actual']);
        $ownerView->update(['visibility' => 'archived']);
        self::assertSame(0, $service->getContractsSummary($owner->id)['total_contracts']);
        $archive = $service->getContractsSummary($owner->id, ['status' => ['archived']]);
        self::assertSame(1, $archive['total_contracts']);
        self::assertSame(150.0, $archive['financial']['total_paid_amount']);
        self::assertSame(300.0, $archive['financial']['total_performed_amount']);
        self::assertSame(999.0, $service->getContractsSummary($other->id)['financial']['total_paid_amount']);
        $otherView->update(['access_revoked_at' => now()]);
        self::assertSame(0, $service->getContractsSummary($other->id, ['contractor_context' => true])['total_contracts']);
        self::assertSame(0.0, $service->getContractsSummary($other->id)['financial']['total_paid_amount']);
    }

    public function test_legacy_lists_respect_each_organization_archive_and_revocation(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'number' => 'LIST-1', 'date' => '2026-09-19', 'status' => 'active', 'total_amount' => 100,
        ]);
        $ownerView = ContractOrganizationView::create(['contract_id' => $contract->id, 'organization_id' => $owner->id, 'visibility' => 'archived']);
        $otherView = ContractOrganizationView::create(['contract_id' => $contract->id, 'organization_id' => $other->id]);
        $repository = app(\App\Repositories\ContractRepository::class);
        self::assertSame(0, $repository->getContractsForOrganizationPaginated($owner->id)->total());
        self::assertSame(1, $repository->getContractsForOrganizationPaginated($other->id)->total());
        self::assertSame(1, $repository->getContractsForOrganizationPaginated($owner->id, 15, ['status' => 'archived'])->total());
        self::assertSame(0, $repository->getContractsForOrganizationPaginated($other->id, 15, ['status' => 'archived'])->total());
        self::assertSame(1, $repository->getContractsForOrganizationPaginated($other->id, 15, ['status' => ['active', 'archived']])->total());
        self::assertSame(0, $repository->getContractsForOrganizationPaginated($other->id, 15, ['status' => 'draft'])->total());
        $ownerView->update(['visibility' => 'trashed']);
        self::assertSame(0, $repository->getContractsForOrganizationPaginated($owner->id, 15, ['status' => 'archived'])->total());
        $otherView->update(['access_revoked_at' => now()]);
        self::assertSame(0, $repository->getContractsForOrganizationPaginated($other->id, 15, ['contractor_context' => true, 'related_party_organization_id' => $owner->id])->total());
        $legacy = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'number' => 'LEGACY-ARCHIVE', 'date' => '2026-09-19', 'status' => 'archived', 'total_amount' => 50,
        ]);
        $page = $repository->getContractsForOrganizationPaginated($owner->id, 15, ['status' => 'archived']);
        self::assertSame(1, $page->total());
        self::assertSame($legacy->id, $page->items()[0]->id);
    }

    public function test_archive_and_trash_are_private_reversible_and_do_not_terminate_the_contract(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'number' => 'ARCHIVE-1', 'date' => '2026-09-19', 'status' => 'active', 'total_amount' => 100,
        ]);
        self::assertSame('Перемещение договоров в корзину и восстановление из неё', \App\Helpers\PermissionTranslator::getPermissionTranslation('contracts.trash', 'contract-management'));
        $ownerView = ContractOrganizationView::create(['contract_id' => $contract->id, 'organization_id' => $owner->id]);
        $otherView = ContractOrganizationView::create(['contract_id' => $contract->id, 'organization_id' => $other->id]);
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->with($actor, 'contracts.archive', ['organization_id' => $owner->id])->andReturn(true);
        $authorization->shouldReceive('can')->with($actor, 'contracts.trash', ['organization_id' => $owner->id])->andReturn(true);
        $authorization->shouldReceive('can')->with($actor, 'contracts.view', ['organization_id' => $owner->id])->andReturn(true);
        $service = new ContractOrganizationViewService($authorization);
        $document = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument::create([
            'organization_id' => $owner->id, 'primary_project_id' => $project->id,
            'title' => 'Подписанный договор', 'document_type' => 'contract',
        ]);
        $contract->update(['legal_archive_document_id' => $document->id]);
        $file = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile::create([
            'document_id' => $document->id, 'organization_id' => $owner->id,
            'role' => 'primary', 'title' => 'Договор.pdf',
        ]);
        $version = \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion::create([
            'document_id' => $document->id, 'document_file_id' => $file->id, 'organization_id' => $owner->id,
            'version_number' => '1', 'is_current' => true, 'status' => 'frozen', 'processing_status' => 'ready',
            'file_path' => 'org-'.$owner->id.'/legal-archive/preserved.pdf', 'original_filename' => 'Договор.pdf',
            'size_bytes' => 20, 'content_hash' => hash('sha256', 'preserved-document'),
        ]);
        $file->update(['current_version_id' => $version->id]);
        $requestId = \Illuminate\Support\Facades\DB::table('legal_signature_requests')->insertGetId([
            'organization_id' => $owner->id, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'method' => 'paper', 'status' => 'pending', 'signed_content_hash' => $version->content_hash,
            'signers' => '[{"name":"Подписант"}]', 'signer_snapshot_hash' => str_repeat('e', 64),
            'profile_code' => 'contract.work', 'profile_lock_version' => 0,
            'allowed_signature_kinds' => '["paper_original"]', 'required_signature_kinds' => '[]',
            'allowed_signature_formats' => '["detached_cades"]', 'requirement_snapshot_hash' => str_repeat('f', 64),
            'requirement_group_key' => str_repeat('1', 64), 'correlation_id' => str_repeat('c', 64),
            'idempotency_key' => 'archive-preservation', 'request_hash' => str_repeat('d', 64),
            'requested_by_user_id' => $actor->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $signatureId = \Illuminate\Support\Facades\DB::table('legal_document_signatures')->insertGetId([
            'organization_id' => $owner->id, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => $requestId, 'method' => 'paper', 'signer_name' => 'Подписант',
            'signers' => '[{"name":"Подписант"}]', 'signed_content_hash' => $version->content_hash,
            'certificate_metadata' => '{}', 'provider_metadata' => '{}', 'storage_location' => 'Сейф организации',
            'signed_at' => now(), 'verification_status' => 'registered', 'signature_kind' => 'paper_original',
            'signer_snapshot_hash' => str_repeat('e', 64), 'authority_confirmed' => false, 'time_source' => 'operator',
            'diagnostic_code' => 'paper_original_registered', 'registered_by_user_id' => $actor->id,
            'idempotency_key' => 'archive-preservation', 'request_hash' => str_repeat('b', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $signatureBefore = (array) \Illuminate\Support\Facades\DB::table('legal_document_signatures')->find($signatureId);
        $storage = \Illuminate\Support\Facades\Storage::fake('s3');
        $storage->put($version->file_path, 'preserved-document');
        $signaturePath = 'org-'.$owner->id.'/legal-archive/preserved.p7s';
        $signatureBytes = 'preserved-electronic-container';
        $storage->put($signaturePath, $signatureBytes);
        $electronicRequest = (array) \Illuminate\Support\Facades\DB::table('legal_signature_requests')->find($requestId);
        unset($electronicRequest['id']);
        $electronicRequestId = \Illuminate\Support\Facades\DB::table('legal_signature_requests')->insertGetId([
            ...$electronicRequest, 'method' => 'external_electronic', 'provider' => 'imported',
            'allowed_signature_kinds' => '["detached_cades"]', 'idempotency_key' => 'archive-electronic',
            'correlation_id' => str_repeat('9', 64),
        ]);
        $electronicSignature = $signatureBefore;
        unset($electronicSignature['id']);
        $electronicSignatureId = \Illuminate\Support\Facades\DB::table('legal_document_signatures')->insertGetId([
            ...$electronicSignature, 'signature_request_id' => $electronicRequestId,
            'method' => 'external_electronic', 'provider' => 'imported', 'storage_location' => null,
            'signature_kind' => 'detached_cades', 'container_format' => 'p7s',
            'signature_path' => $signaturePath, 'signature_content_hash' => hash('sha256', $signatureBytes),
            'storage_etag' => 'test-etag', 'detected_mime_type' => 'application/pkcs7-signature',
            'verification_status' => 'pending_verification', 'diagnostic_code' => 'pending_verification',
            'idempotency_key' => 'archive-electronic',
        ]);
        $artifactId = \Illuminate\Support\Facades\DB::table('legal_signature_artifacts')->insertGetId([
            'organization_id' => $owner->id, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => $electronicRequestId, 'artifact_key' => str_repeat('7', 64),
            'storage_path' => $signaturePath, 'storage_etag' => 'test-etag',
            'content_hash' => hash('sha256', $signatureBytes), 'put_request_hash' => str_repeat('6', 64),
            'state' => 'referenced', 'referenced_signature_id' => $electronicSignatureId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $act = $contract->performanceActs()->create([
            'project_id' => $project->id, 'act_document_number' => 'PRESERVED-ACT', 'act_date' => '2026-09-19',
            'amount' => 100, 'status' => 'approved', 'is_approved' => true,
        ]);
        $payment = $contract->payments()->create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'invoiceable_type' => Contract::class, 'document_number' => 'PRESERVED-PAYMENT',
            'document_date' => '2026-09-19', 'document_type' => 'invoice', 'direction' => 'outgoing',
            'amount' => 100, 'paid_amount' => 100, 'remaining_amount' => 0, 'currency' => 'RUB', 'status' => 'paid',
        ]);
        $executionSnapshot = static fn (): array => [
            $act->fresh()->getRawOriginal(), $payment->fresh()->getRawOriginal(),
            (array) \Illuminate\Support\Facades\DB::table('legal_document_signatures')->find($electronicSignatureId),
            (array) \Illuminate\Support\Facades\DB::table('legal_signature_artifacts')->find($artifactId),
            $storage->get($version->file_path), $storage->get($signaturePath),
        ];
        $executionBefore = $executionSnapshot();
        $obligationId = \Illuminate\Support\Facades\DB::table('legal_document_obligations')->insertGetId([
            'organization_id' => $owner->id, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'project_id' => $project->id, 'title' => 'Исполненное обязательство', 'status' => 'completed',
            'completed_at' => now(), 'amount' => 100, 'evidence' => json_encode(['act' => 'А-1']),
        ]);
        $preserved = [$document->fresh()->getRawOriginal(), $file->fresh()->getRawOriginal(), $version->fresh()->getRawOriginal(),
            (array) \Illuminate\Support\Facades\DB::table('legal_document_obligations')->find($obligationId)];
        $original = $contract->fresh()->getRawOriginal();

        try {
            $service->transition($actor, $owner->id, $contract->id, 'trash', 1);
            self::fail('Active contracts must be archived before trash');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        foreach ([['archive', 'archived'], ['trash', 'trashed'], ['restore', 'archived'], ['unarchive', 'active']] as $index => [$action, $visibility]) {
            $view = $service->transition($actor, $owner->id, $contract->id, $action, $index + 1);
            self::assertSame($visibility, $view->visibility);
            self::assertSame($index + 2, $view->version);
            $repeat = $service->transition($actor, $owner->id, $contract->id, $action, $index + 1);
            self::assertSame($view->version, $repeat->version);
            self::assertSame(1, $service->list($actor, $owner->id, ['visibility' => $visibility])->total());
            self::assertSame('active', $otherView->fresh()->visibility);
            self::assertSame($original, $contract->fresh()->getRawOriginal());
            self::assertSame($preserved, [$document->fresh()->getRawOriginal(), $file->fresh()->getRawOriginal(), $version->fresh()->getRawOriginal(),
                (array) \Illuminate\Support\Facades\DB::table('legal_document_obligations')->find($obligationId)]);
            self::assertSame($signatureBefore, (array) \Illuminate\Support\Facades\DB::table('legal_document_signatures')->find($signatureId));
            self::assertSame($executionBefore, $executionSnapshot());
        }
        self::assertSame(2, ContractOrganizationView::where('contract_id', $contract->id)->count());
        $events = \Illuminate\Support\Facades\DB::table('contract_organization_view_events')->where('view_id', $ownerView->id)->orderBy('version')->get();
        self::assertCount(4, $events);
        self::assertSame(['archive', 'trash', 'restore', 'unarchive'], $events->pluck('action')->all());
        self::assertSame([$actor->id], $events->pluck('actor_id')->unique()->all());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('contract_organization_view_events')->where('view_id', $otherView->id)->count());
        $recordedActorName = $actor->name;
        $actor->update(['name' => 'Новое имя сотрудника']);
        $history = $service->history($actor, $owner->id, $contract->id, ['per_page' => 2]);
        self::assertSame(4, $history->total());
        self::assertCount(2, $history->items());
        self::assertSame('unarchive', $history->items()[0]->action);
        self::assertSame($recordedActorName, $history->items()[0]->actor_name);
        self::assertSame('archive', $service->history($actor, $owner->id, $contract->id, ['per_page' => 2, 'page' => 2])->items()[1]->action);
        $historyActor = User::factory()->create(['current_organization_id' => $other->id]);
        $authorization->shouldReceive('can')->with($historyActor, 'contracts.view', ['organization_id' => $other->id])->andReturn(true);
        self::assertSame(0, $service->history($historyActor, $other->id, $contract->id)->total());
        try {
            $service->transition($actor, $owner->id, $contract->id, 'archive', 1);
            self::fail('Old requests cannot replay after a later transition');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame('active', $ownerView->fresh()->visibility);
        }
        $this->app->instance(ContractOrganizationViewService::class, $service);
        $lifecycle = app(\App\Services\Contract\ContractLifecycleService::class);
        $lifecycle->transition($contract, 'archive', $actor, null);
        $lifecycle->transition($contract, 'archive', $actor, null);
        self::assertSame('archived', $ownerView->fresh()->visibility);
        self::assertSame('active', $otherView->fresh()->visibility);
        self::assertSame($original, $contract->fresh()->getRawOriginal());
        self::assertSame(5, \Illuminate\Support\Facades\DB::table('contract_organization_view_events')->where('view_id', $ownerView->id)->count());
        try {
            $otherActor = User::factory()->create(['current_organization_id' => $other->id]);
            $lifecycle->transition($contract, 'terminate', $otherActor, 'Недопустимо');
            self::fail('A shared reader cannot change the owner legal lifecycle');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        try {
            app(\App\Services\Contract\ContractService::class)->deleteContract($contract->id, $owner->id);
            self::fail('Legacy service must not delete contracts');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame($original, $contract->fresh()->getRawOriginal());
        }
        $authorization->shouldReceive('can')->with($historyActor, 'contracts.archive', ['organization_id' => $other->id])->andReturn(true);
        $authorization->shouldReceive('can')->with($historyActor, 'contracts.trash', ['organization_id' => $other->id])->andReturn(true);
        foreach ([['archive', 'archived'], ['trash', 'trashed'], ['restore', 'archived'], ['unarchive', 'active']] as $index => [$action, $visibility]) {
            $view = $service->transition($historyActor, $other->id, $contract->id, $action, $index + 1);
            self::assertSame($visibility, $view->visibility);
            self::assertSame('archived', $ownerView->fresh()->visibility);
            self::assertSame($original, $contract->fresh()->getRawOriginal());
            self::assertSame($preserved, [$document->fresh()->getRawOriginal(), $file->fresh()->getRawOriginal(), $version->fresh()->getRawOriginal(),
                (array) \Illuminate\Support\Facades\DB::table('legal_document_obligations')->find($obligationId)]);
            self::assertSame($signatureBefore, (array) \Illuminate\Support\Facades\DB::table('legal_document_signatures')->find($signatureId));
            self::assertSame($executionBefore, $executionSnapshot());
        }
        self::assertSame(4, $service->history($historyActor, $other->id, $contract->id)->total());
        self::assertSame(5, $service->history($actor, $owner->id, $contract->id)->total());
        $ownerView->update(['access_revoked_at' => now()]);
        try {
            $service->history($actor, $owner->id, $contract->id);
            self::fail('Revoked organization history must be unavailable');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
        $denied = \Mockery::mock(AuthorizationService::class);
        $denied->shouldReceive('can')->with($actor, 'contracts.trash', ['organization_id' => $owner->id])->andReturn(false);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        (new ContractOrganizationViewService($denied))->transition($actor, $owner->id, $contract->id, 'trash', 5);
    }

    public function test_parties_share_legal_identity_but_not_internal_data_or_project_access(): void
    {
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contractor = Contractor::create([
            'organization_id' => $owner->id, 'source_organization_id' => $executor->id,
            'name' => $executor->name, 'contractor_type' => 'invited_organization',
        ]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'contract_side_type' => 'subcontract', 'number' => 'SHARED-1', 'date' => '2026-09-19',
            'status' => 'draft', 'total_amount' => 100, 'notes' => 'Секрет владельца',
            'actual_advance_amount' => 70,
        ]);
        app(ContractPartySnapshotService::class)->syncParties($contract);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $service = new ContractOrganizationViewService($authorization);
        $contractCount = Contract::count();
        $paymentCount = \App\BusinessModules\Core\Payments\Models\PaymentDocument::count();
        $participants = \Illuminate\Support\Facades\DB::table('project_organization')->where('project_id', $project->id)->count();
        $service->synchronizeNewContract($contract);
        $service->synchronizeNewContract($contract);
        self::assertSame(2, ContractOrganizationView::where('contract_id', $contract->id)->count());
        $ownerUser = User::factory()->create(['current_organization_id' => $owner->id]);
        $executorUser = User::factory()->create(['current_organization_id' => $executor->id]);
        $ownerView = $service->find($ownerUser, $owner->id, $contract->id);
        $executorView = $service->find($executorUser, $executor->id, $contract->id);
        $page = $service->list($executorUser, $executor->id, ['search' => 'SHARED', 'per_page' => 1]);
        self::assertSame(1, $page->total());
        self::assertSame($executorView->id, $page->items()[0]->id);
        self::assertSame(0, $service->list($executorUser, $executor->id, ['search' => 'not-found'])->total());
        $service->updateNotes($ownerUser, $owner->id, $contract->id, 'Внутренняя заметка', 1);
        self::assertNull($executorView->fresh()->private_notes);
        $ownerData = (new ContractOrganizationViewResource($ownerView->fresh()))->resolve(Request::create('/'));
        $executorData = (new ContractOrganizationViewResource($executorView->fresh()))->resolve(Request::create('/'));
        self::assertSame($ownerData['legal_contract'], $executorData['legal_contract']);
        self::assertSame('Внутренняя заметка', $ownerData['private_notes']);
        self::assertNull($executorData['private_notes']);
        self::assertArrayNotHasKey('notes', $executorData['legal_contract']);
        self::assertArrayNotHasKey('actual_advance_amount', $executorData['legal_contract']);
        self::assertArrayNotHasKey('payments', $executorData['legal_contract']);
        self::assertSame($contractCount, Contract::count());
        self::assertSame($paymentCount, \App\BusinessModules\Core\Payments\Models\PaymentDocument::count());
        self::assertSame($participants, \Illuminate\Support\Facades\DB::table('project_organization')->where('project_id', $project->id)->count());
        $access = app(\App\Services\Contract\ContractAccessService::class);
        $sharedContract = $access->findAccessibleOrFail($contract->id, $executor->id);
        self::assertFalse($sharedContract->relationLoaded('payments'));
        self::assertFalse($sharedContract->relationLoaded('performanceActs'));
        $request = Request::create('/contracts/'.$contract->id);
        $request->attributes->set('current_organization_id', $executor->id);
        $legacyData = (new \App\Http\Resources\Api\V1\Admin\Contract\ContractResource($sharedContract))->resolve($request);
        self::assertArrayNotHasKey('notes', $legacyData);
        self::assertArrayNotHasKey('actual_advance_amount', $legacyData);
        self::assertArrayNotHasKey('payments', $legacyData);
        self::assertSame('income', $legacyData['contract_side']['direction']);
        $legacyService = \Mockery::mock(\App\Services\Contract\ContractService::class);
        $legacyService->shouldNotReceive('getFullContractDetails');
        $read = new \App\Services\Contract\ContractReadService($access, $legacyService);
        $full = $read->fullDetails($contract->id, $executor->id);
        self::assertSame([], $full['payments']);
        self::assertNull($full['analytics']);
        self::assertArrayNotHasKey('notes', $full['contract']->resolve(Request::create('/')));
        foreach ([fn () => $read->analytics($contract->id, $executor->id), fn () => $read->completedWorks($contract->id, $executor->id)] as $privateRead) {
            try {
                $privateRead();
                self::fail('Other organization accounting must not be returned');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                self::assertTrue(true);
            }
        }
        try {
            $service->updateNotes($ownerUser, $owner->id, $contract->id, 'Устаревшая заметка', 1);
            self::fail('Stale private state must not overwrite current notes');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame('Внутренняя заметка', $ownerView->fresh()->private_notes);
        }
        try {
            $service->find($executorUser, $owner->id, $contract->id);
            self::fail('Actor cannot impersonate the other organization');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
        $replacement = Organization::factory()->create();
        $contractor->update(['source_organization_id' => $replacement->id]);
        app(ContractPartySnapshotService::class)->syncParties($contract->fresh(), true);
        $service->synchronizeNewContract($contract);
        self::assertNotNull($executorView->fresh()->access_revoked_at);
        self::assertSame(0, $service->list($executorUser, $executor->id)->total());
        self::assertSame(3, ContractOrganizationView::where('contract_id', $contract->id)->count());
        $contractor->update(['source_organization_id' => $executor->id]);
        self::assertNull($access->findAccessible($contract->id, $executor->id));
        self::assertFalse($access->canAccess($contract->fresh(), $executor->id));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->find($executorUser, $executor->id, $contract->id);
    }
}
