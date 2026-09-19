<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractOrganizationEnrollmentService;
use App\Services\Contract\ContractOrganizationViewService;
use App\Services\Contract\ContractPartySnapshotService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractOrganizationEnrollmentTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    public function test_selected_contract_enrollment_preserves_saved_parties_and_rejects_stale_preview(): void
    {
        $this->enableImmutableAuditWriter();
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contractor = Contractor::create(['organization_id' => $owner->id, 'source_organization_id' => $executor->id, 'name' => $executor->name, 'contractor_type' => 'invited_organization']);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'ENROLL-1', 'date' => '2026-09-19', 'status' => 'active', 'total_amount' => 100, 'contract_side_type' => 'subcontract',
        ]);
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $service = new ContractOrganizationEnrollmentService($authorization, new ContractOrganizationViewService($authorization));
        self::assertFalse($service->preview($actor, $owner->id, $contract->id)['can_enable']);
        app(ContractPartySnapshotService::class)->syncParties($contract);
        $preview = $service->preview($actor, $owner->id, $contract->id);
        self::assertTrue($preview['can_enable']);
        self::assertSame(0, $contract->organizationViews()->count());
        $contract->update(['status' => 'archived']);
        $blocked = $service->preview($actor, $owner->id, $contract->id);
        self::assertFalse($blocked['can_enable']);
        self::assertNull($blocked['restored_status']);
        app(\App\Services\Contract\ContractStateEventService::class)->createStatusTransitionEvent(
            $contract, 'archive', 'active', 'archived', 'Историческая архивация', $actor->id,
        );
        $archivedPreview = $service->preview($actor, $owner->id, $contract->id);
        self::assertTrue($archivedPreview['can_enable']);
        self::assertSame('active', $archivedPreview['restored_status']);
        $act = $contract->performanceActs()->create([
            'project_id' => $project->id, 'act_document_number' => 'ENROLL-ACT',
            'act_date' => '2026-09-19', 'amount' => 50, 'status' => 'draft', 'is_approved' => true,
        ]);
        $payment = $contract->payments()->create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'invoiceable_type' => Contract::class, 'document_number' => 'ENROLL-PAY',
            'document_date' => '2026-09-19', 'document_type' => 'invoice', 'direction' => 'outgoing',
            'invoice_type' => 'act', 'amount' => 100, 'paid_amount' => 50, 'currency' => 'RUB', 'status' => 'draft',
        ]);
        $actBefore = $act->fresh()->getRawOriginal();
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $limitedAuthorization = \Mockery::mock(AuthorizationService::class);
        $limitedAuthorization->shouldReceive('can')->andReturnUsing(
            fn (User $user, string $permission): bool => $permission !== 'contracts.archive',
        );
        $limited = new ContractOrganizationEnrollmentService($limitedAuthorization, new ContractOrganizationViewService($limitedAuthorization));
        try {
            $limited->enable($actor, $owner->id, $contract->id, $archivedPreview['fingerprint'], 'Перевод архива');
            self::fail('Archive permission must be required for legacy archive enrollment');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('archived', $contract->fresh()->status->value);
            self::assertSame(0, $contract->organizationViews()->count());
            self::assertSame(0, DB::table('contract_organization_enrollments')->where('contract_id', $contract->id)->count());
            self::assertSame(1, DB::table('contract_state_events')->where('contract_id', $contract->id)->count());
            self::assertSame(0, DB::table('immutable_audit_events')->where('event_type', 'contract.organization_view_enrollment')->where('subject_id', (string) $contract->id)->count());
        }
        $archivedView = $service->enable($actor, $owner->id, $contract->id, $archivedPreview['fingerprint'], 'Перевод архива');
        self::assertSame('archived', $archivedView->visibility);
        self::assertSame('active', $contract->fresh()->status->value);
        self::assertSame('active', $contract->organizationViews()->where('organization_id', $executor->id)->value('visibility'));
        self::assertSame($archivedView->id, $service->enable($actor, $owner->id, $contract->id, $archivedPreview['fingerprint'], 'Перевод архива')->id);
        self::assertSame('archived', DB::table('contract_organization_enrollments')->where('contract_id', $contract->id)->value('original_status'));
        self::assertSame($actBefore, $act->fresh()->getRawOriginal());
        self::assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        self::assertSame(1, DB::table('immutable_audit_events')->where('event_type', 'contract.organization_view_enrollment')->where('subject_id', (string) $contract->id)->count());

        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'ENROLL-2', 'date' => '2026-09-19', 'status' => 'active', 'total_amount' => 100, 'contract_side_type' => 'subcontract',
        ]);
        app(ContractPartySnapshotService::class)->syncParties($contract);
        $preview = $service->preview($actor, $owner->id, $contract->id);
        $contract->update(['notes' => 'Изменено после проверки']);
        try {
            $service->enable($actor, $owner->id, $contract->id, $preview['fingerprint'], 'Перевод выбранного договора');
            self::fail('A stale preview must not enroll the contract');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame(0, $contract->organizationViews()->count());
        }
        $preview = $service->preview($actor, $owner->id, $contract->id);
        $parties = $contract->parties()->orderBy('id')->get()->toArray();
        $original = $contract->fresh()->getRawOriginal();
        $replacement = Organization::factory()->create();
        $contractor->update(['source_organization_id' => $replacement->id, 'name' => 'Изменённый справочник']);
        $view = $service->enable($actor, $owner->id, $contract->id, $preview['fingerprint'], 'Перевод выбранного договора');
        $repeat = $service->enable($actor, $owner->id, $contract->id, $preview['fingerprint'], 'Перевод выбранного договора');
        self::assertSame($view->id, $repeat->id);
        self::assertSame($original, $contract->fresh()->getRawOriginal());
        self::assertSame($parties, $contract->parties()->orderBy('id')->get()->toArray());
        self::assertEqualsCanonicalizing([$owner->id, $executor->id], $contract->organizationViews()->pluck('organization_id')->all());
        self::assertSame(1, DB::table('contract_organization_enrollments')->where('contract_id', $contract->id)->count());
        self::assertSame('Перевод выбранного договора', DB::table('contract_organization_enrollments')->where('contract_id', $contract->id)->value('reason'));
        $contract->update(['status' => 'archived']);
        self::assertFalse($service->preview($actor, $owner->id, $contract->id)['can_enable']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $otherActor = User::factory()->create(['current_organization_id' => $executor->id]);
        $service->preview($otherActor, $executor->id, $contract->id);
    }
}
