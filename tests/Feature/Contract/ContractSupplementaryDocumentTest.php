<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Core\Payments\DTOs\FinancialBalance;
use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\SupplementaryAgreementDTO;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractBuilderInstanceService;
use App\Services\Contract\ContractLibraryService;
use App\Services\Contract\ContractOrganizationViewService;
use App\Services\Contract\ContractRevisionActivationService;
use App\Services\Contract\ContractRevisionApplicationService;
use App\Services\Contract\ContractRevisionConfirmationService;
use App\Services\Contract\ContractSupplementaryActivationService;
use App\Services\Contract\ContractSupplementaryApplicationService;
use App\Services\Contract\ContractSupplementaryConfirmationService;
use App\Services\Contract\ContractSupplementaryDocumentService;
use App\Services\Contract\SupplementaryAgreementService;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractSupplementaryDocumentTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    public function test_draft_inherits_effective_values_and_rejects_empty_changes(): void
    {
        [$actor, , $contract, $priceId, $frameId] = $this->effectiveFixture();
        $service = app(ContractSupplementaryDocumentService::class);
        $document = $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-1', now()->toDateString(), 'create-1');
        self::assertSame('120', ((array) json_decode(json_encode($document['base_values'], JSON_THROW_ON_ERROR), true))[$priceId]['amount']);
        $values = (array) json_decode(json_encode($document['values'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        try {
            $service->saveDraft($actor, $actor->current_organization_id, $contract->id, $document['id'], $values, 1, 'same-values');
            self::fail('Empty changes must be rejected');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    public function test_preview_renders_frame_variables_from_matching_document_values(): void
    {
        [$actor, , $contract, $priceId] = $this->effectiveFixture();
        $library = app(ContractLibraryService::class);
        $frame = $library->create($actor, $actor->current_organization_id, 'template', 'Допсоглашение с ценой', [
            'document' => ['type' => 'doc', 'content' => [[
                'type' => 'clause', 'attrs' => ['id' => 'price'], 'content' => [[
                    'type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $priceId]]],
                ]],
            ]]],
            'variables' => [$priceId => 1],
        ], 'supplementary-frame-values');
        $frameId = $frame['item']['id'];
        $library->publish($actor, $actor->current_organization_id, $frameId, 1, 1);
        $service = app(ContractSupplementaryDocumentService::class);
        $document = $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-preview', now()->toDateString(), 'preview-values');

        $preview = $service->preview($actor, $actor->current_organization_id, $contract->id, $document['id']);

        self::assertStringContainsString('120', $preview['html']);
    }

    public function test_confirmation_of_unchanged_supplementary_document_returns_validation_error(): void
    {
        [$actor, , $contract, , $frameId] = $this->effectiveFixture();
        $service = app(ContractSupplementaryDocumentService::class);
        $document = $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-empty', now()->toDateString(), 'confirm-empty');

        try {
            app(ContractSupplementaryConfirmationService::class)->confirm(
                $actor, $actor->current_organization_id, $contract->id, $document['id'], $document['content_hash'], 'confirm-empty'
            );
            self::fail('An unchanged supplementary document must not be confirmed');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame('contracts.supplementary_changes_required', $exception->messageKey());
        }
    }

    public function test_confirmation_and_activation_change_price_and_specification_without_touching_revision(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, $priceId, $frameId, $revision, $worksId] = $this->effectiveFixture();
        $effectiveBefore = DB::table('contract_builder_instances')->where('contract_id', $contract->id)->value('effective_revision_id');
        $revisionHash = $revision['content_hash'];
        $revisionText = DB::table('contract_builder_revisions')->where('id', $revision['id'])->value('document');
        $service = app(ContractSupplementaryDocumentService::class);
        $document = $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-1', now()->toDateString(), 'create-activate');
        $values = json_decode(DB::table('contract_supplementary_documents')->where('id', $document['id'])->value('values'), true, 512, JSON_THROW_ON_ERROR);
        $values[$priceId] = ['amount' => '200', 'currency' => 'RUB'];
        $values[$worksId] = [['id' => 'work-1', 'values' => ['name' => 'Монтаж', 'unit' => 'шт.', 'quantity' => '2', 'price' => ['amount' => '100', 'currency' => 'RUB']]]];
        $document = $service->saveDraft($actor, $actor->current_organization_id, $contract->id, $document['id'], $values, 1, 'draft-price');
        self::assertNotEmpty($document['changes']);
        $confirmations = app(ContractSupplementaryConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, $document['id'], $document['content_hash'], 'confirm');
        }
        $activation = app(ContractSupplementaryActivationService::class)->schedule(
            $actor, $actor->current_organization_id, $contract->id, $document['id'],
            $document['content_hash'], null, now()->toDateString(), 'Допсоглашение №1', 'activate'
        );
        $applied = app(ContractSupplementaryApplicationService::class)->applyDue($activation['id']);
        self::assertSame('applied', $applied['status']);
        self::assertSame('200.00', $contract->refresh()->total_amount);
        self::assertSame(2, $contract->specifications()->count());
        self::assertSame(1, $contract->specifications()->wherePivot('is_active', true)->count());
        self::assertNotNull(\App\Models\Specification::where('supplementary_document_id', $document['id'])->first());
        self::assertSame($revisionHash, DB::table('contract_builder_revisions')->where('id', $revision['id'])->value('content_hash'));
        self::assertSame($revisionText, DB::table('contract_builder_revisions')->where('id', $revision['id'])->value('document'));
        self::assertSame((int) $effectiveBefore, (int) DB::table('contract_builder_instances')->where('contract_id', $contract->id)->value('effective_revision_id'));
        try {
            app(\App\Services\Contract\ContractAuditedMutationService::class)->update($contract, ['total_amount' => '250'], 'manual_edit', $actor->id);
            self::fail('Direct update must stay blocked');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
        }
    }

    public function test_next_document_uses_applied_values_as_base(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, $priceId, $frameId] = $this->effectiveFixture();
        $this->applyPriceChange($actor, $other, $contract, $priceId, $frameId, '200', 'first');
        $service = app(ContractSupplementaryDocumentService::class);
        $second = $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-2', now()->toDateString(), 'create-second');
        $base = (array) json_decode(json_encode($second['base_values'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('200', $base[$priceId]['amount']);
    }

    public function test_second_open_document_is_rejected(): void
    {
        [$actor, , $contract, , $frameId] = $this->effectiveFixture();
        $service = app(ContractSupplementaryDocumentService::class);
        $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-1', now()->toDateString(), 'open-1');
        try {
            $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-2', now()->toDateString(), 'open-2');
            self::fail('Second open document must be rejected');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
        }
    }

    public function test_legacy_agreement_blocked_with_builder_and_allowed_without(): void
    {
        [$actor, , $contract] = $this->effectiveFixture();
        $legacy = app(SupplementaryAgreementService::class);
        try {
            $legacy->create(new SupplementaryAgreementDTO($contract->id, 'LEGACY-1', now()->toDateString(), 10.0, [], null, null, null));
            self::fail('Legacy create must be blocked for builder contracts');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $forced = \App\Models\SupplementaryAgreement::create([
            'contract_id' => $contract->id, 'number' => 'FORCED', 'agreement_date' => now()->toDateString(),
            'change_amount' => 10, 'subject_changes' => [],
        ]);
        try {
            $legacy->applyOnce($forced, $actor->id);
            self::fail('Legacy applyOnce must be blocked for builder contracts');
        } catch (\App\Exceptions\ContractBuilderException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $plain = Contract::create([
            'organization_id' => $actor->current_organization_id, 'project_id' => $contract->project_id,
            'contractor_id' => $contract->contractor_id, 'contract_side_type' => 'subcontract',
            'number' => 'PLAIN-1', 'date' => '2026-09-19', 'status' => 'active', 'total_amount' => 100,
        ]);
        $created = $legacy->create(new SupplementaryAgreementDTO($plain->id, 'LEGACY-OK', now()->toDateString(), 5.0, [], null, null, null));
        self::assertNotNull($created->id);
    }

    public function test_customer_agreements_summary_includes_applied_supplementary_document(): void
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, $priceId, $frameId] = $this->effectiveFixture();
        $this->applyPriceChange($actor, $other, $contract, $priceId, $frameId, '200', 'customer');
        $documentId = (int) DB::table('contract_supplementary_documents')->where('contract_id', $contract->id)->where('status', 'applied')->value('id');
        $portal = app(CustomerPortalService::class);
        $method = new \ReflectionMethod($portal, 'mapCustomerContractDetails');
        $method->setAccessible(true);
        $details = $method->invoke($portal, $contract->fresh()->load('agreements'), FinancialBalance::fromLedger('0', '0', '0'));
        $items = $details['agreements_summary']['items'];
        self::assertSame(1, $details['agreements_summary']['count']);
        self::assertSame($documentId, $items[0]['id']);
        self::assertSame(80.0, $items[0]['change_amount']);
        self::assertContains('agreement', array_column($details['timeline'], 'type'));
    }

    private function applyPriceChange(User $actor, User $other, Contract $contract, string $priceId, string $frameId, string $amount, string $key): void
    {
        $service = app(ContractSupplementaryDocumentService::class);
        $document = $service->create($actor, $actor->current_organization_id, $contract->id, $frameId, 1, 'ДС-'.$key, now()->toDateString(), 'create-'.$key);
        $values = json_decode(DB::table('contract_supplementary_documents')->where('id', $document['id'])->value('values'), true, 512, JSON_THROW_ON_ERROR);
        $values[$priceId] = ['amount' => $amount, 'currency' => 'RUB'];
        $document = $service->saveDraft($actor, $actor->current_organization_id, $contract->id, $document['id'], $values, 1, 'draft-'.$key);
        $confirmations = app(ContractSupplementaryConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, $document['id'], $document['content_hash'], 'confirm-'.$key);
        }
        $activation = app(ContractSupplementaryActivationService::class)->schedule(
            $actor, $actor->current_organization_id, $contract->id, $document['id'],
            $document['content_hash'], $document['previous_document_id'] ?? null, now()->toDateString(), 'Основание '.$key, 'activate-'.$key
        );
        app(ContractSupplementaryApplicationService::class)->applyDue($activation['id']);
    }

    private function effectiveFixture(): array
    {
        $this->enableImmutableAuditWriter();
        [$actor, $other, $contract, $priceId, $templateId, $revision, $worksId] = $this->fixture();
        app(\App\Services\Contract\ContractStateEventService::class)->createContractCreatedEvent($contract, null, $actor->id);
        $confirmations = app(ContractRevisionConfirmationService::class);
        foreach ([$actor, $other] as $party) {
            $confirmations->confirm($party, $party->current_organization_id, $contract->id, 1, $revision['content_hash'], 'confirm-base');
        }
        $activation = app(ContractRevisionActivationService::class)->schedule(
            $actor, $actor->current_organization_id, $contract->id, 1, $revision['content_hash'], null, now()->toDateString(), 'Ввод редакции', 'activate-base'
        );
        app(ContractRevisionApplicationService::class)->applyDue($activation['id']);
        $contract->refresh();
        self::assertSame('active', $contract->getRawOriginal('status'));
        self::assertNotNull(DB::table('contract_builder_instances')->where('contract_id', $contract->id)->value('effective_revision_id'));
        $library = app(ContractLibraryService::class);
        $frame = $library->create($actor, $actor->current_organization_id, 'template', 'Допсоглашение', [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Дополнительное соглашение']]]]],
            'variables' => [],
        ], 'supplementary-frame');
        $frameId = $frame['item']['id'];
        $library->publish($actor, $actor->current_organization_id, $frameId, 1, 1);

        return [$actor, $other, $contract, $priceId, $frameId, $revision, $worksId];
    }

    private function fixture(): array
    {
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $other = User::factory()->create(['current_organization_id' => $executor->id]);
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contractor = \App\Models\Contractor::create([
            'organization_id' => $owner->id, 'source_organization_id' => $executor->id, 'name' => $executor->name,
        ]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'contract_side_type' => 'subcontract',
            'number' => 'DRAFT-1', 'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100,
        ]);
        foreach ([['first', 'contractor', $owner], ['second', 'subcontractor', $executor]] as [$side, $role, $organization]) {
            $contract->parties()->create(['side' => $side, 'role' => $role, 'linked_organization_id' => $organization->id, 'name' => $organization->name, 'snapshot' => []]);
        }
        app(ContractOrganizationViewService::class)->synchronizeNewContract($contract);
        $library = app(ContractLibraryService::class);
        $variable = $library->create($actor, $owner->id, 'variable', 'Цена', ['type' => 'money', 'required' => true, 'assignment' => ['target' => 'price']], 'variable');
        $variableId = $variable['item']['id'];
        $library->publish($actor, $owner->id, $variableId, 1, 1);
        $works = $library->create($actor, $owner->id, 'variable', 'План работ', ['type' => 'table', 'columns' => [
            ['id' => 'name', 'label' => 'Работа', 'definition' => ['type' => 'text']],
            ['id' => 'unit', 'label' => 'Единица', 'definition' => ['type' => 'text']],
            ['id' => 'quantity', 'label' => 'Объём', 'definition' => ['type' => 'number']],
            ['id' => 'price', 'label' => 'Цена', 'definition' => ['type' => 'money']],
        ], 'assignment' => ['target' => 'works', 'columns' => ['name' => 'name', 'unit' => 'unit', 'quantity' => 'quantity', 'price' => 'price']]], 'works');
        $worksId = $works['item']['id'];
        $library->publish($actor, $owner->id, $worksId, 1, 1);
        $content = [
            'document' => ['type' => 'doc', 'content' => [
                ['type' => 'clause', 'attrs' => ['id' => 'price'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $variableId]]]]]],
                ['type' => 'clause', 'attrs' => ['id' => 'works'], 'content' => [
                    ['type' => 'table', 'content' => [['type' => 'repeatRows', 'attrs' => ['variableId' => $worksId], 'content' => [
                        ['type' => 'tableRow', 'content' => array_map(static fn (string $column): array => [
                            'type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [
                                ['type' => 'variable', 'attrs' => ['variableId' => $worksId, 'columnId' => $column]],
                            ]]],
                        ], ['name', 'unit', 'quantity', 'price'])],
                    ]]]],
                ]],
            ]],
            'variables' => [$variableId => 1, $worksId => 1],
        ];
        $template = $library->create($actor, $owner->id, 'template', 'Договор', $content, 'template');
        $templateId = $template['item']['id'];
        $library->publish($actor, $owner->id, $templateId, 1, 1);
        $revision = app(ContractBuilderInstanceService::class)->create($actor, $owner->id, $contract->id, $templateId, 1, [
            $variableId => ['amount' => '120', 'currency' => 'RUB'],
            $worksId => [['id' => 'work-1', 'values' => ['name' => 'Монтаж', 'unit' => 'шт.', 'quantity' => '2', 'price' => ['amount' => '60', 'currency' => 'RUB']]]],
        ], 'instance');

        return [$actor, $other, $contract, $variableId, $templateId, $revision, $worksId];
    }
}
