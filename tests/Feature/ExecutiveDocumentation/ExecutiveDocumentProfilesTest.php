<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_geodetic_acts_can_be_registered_before_daily_production_records_exist(): void
    {
        [$actor, $set, $service] = $this->fixture();
        foreach ([
            'geodetic_base_acceptance_act' => ['act_number' => 'ГРО-1', 'geodetic_base_description' => 'Реперы и пункты основы', 'base_acceptance_documents' => 'Схема передачи ГРО'],
            'axis_layout_act' => ['act_number' => 'ОСИ-1', 'axis_layout_text' => 'Разбивка осей А-Д', 'axis_fixing_text' => 'Закреплены знаками'],
        ] as $type => $profile) {
            $data = [
                'document_type' => $type, 'title' => 'Геодезический акт', 'profile_data' => $profile,
                'initial_version' => ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('geodetic.pdf', $type)],
            ];
            $data = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentInput::class)->normalize($data, $set);
            $document = $service->addDocument($set, $actor->user->id, $data);
            self::assertNull($document->work_type_id);
            self::assertNull($document->journal_entry_id);
            $submitted = $service->submit($document, $actor->user->id, null, $document->versions->first()->id);
            self::assertSame('under_review', $submitted->status->value);
        }
    }

    public function test_ready_external_act_can_be_registered_without_retyping_template_fields(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $data = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentInput::class)->normalize([
            'document_type' => 'hidden_work_act',
            'title' => 'Акт из файла',
            'initial_version' => ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('act.pdf', 'ready act')],
        ], $set);

        $document = $service->addDocument($set, $actor->user->id, $data);

        self::assertSame('uploaded', $document->metadata['capture_mode']);
        self::assertSame('registered_external', $document->versions->first()->metadata['origin']);
        self::assertNotEmpty($document->versions->first()->content_hash);
    }

    public function test_act_composition_creates_a_generated_draft_separately_from_file_upload(): void
    {
        [$actor, $set] = $this->fixture();
        try {
            app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPreparationService::class)->compose($set, $actor->user->id, [
                'document_type' => 'geodetic_base_acceptance_act', 'title' => 'Без участников',
                'profile_data' => ['act_number' => 'ГО-0', 'geodetic_base_description' => 'Реперы', 'base_acceptance_documents' => 'Схема'],
            ]);
            self::fail('Акт без обязательных участников не должен создаваться.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, $set->documents()->count());
        }
        $document = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPreparationService::class)->compose($set, $actor->user->id, [
            'document_type' => 'geodetic_base_acceptance_act',
            'title' => 'Акт геодезической основы',
            'profile_data' => [
                'act_number' => 'ГО-1',
                'geodetic_base_description' => 'Реперы участка',
                'base_acceptance_documents' => 'Схема передачи',
            ],
            'signatories' => array_map(static fn (string $role): array => [
                'role' => $role, 'name' => 'Иванов И.И.', 'organization' => 'Строительная организация', 'authority_document' => 'Доверенность №1',
            ], ['developer_control_representative', 'construction_representative', 'contractor_control_representative']),
        ]);

        self::assertSame('generated', $document->metadata['capture_mode']);
        self::assertSame('generated_preparation', $document->versions->first()->metadata['origin']);
        self::assertSame('generated_draft', $document->versions->first()->metadata['file_kind']);
        self::assertNotEmpty($document->versions->first()->content_hash);
    }

    public function test_uploaded_scan_and_electronic_original_keep_distinct_file_provenance(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $scan = $service->addDocument($set, $actor->user->id, [
            'document_type' => 'quality_passport', 'title' => 'Скан паспорта',
            'initial_version' => ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('scan.pdf', 'paper scan'), 'file_kind' => 'paper_scan'],
        ]);
        $electronic = $service->addDocument($set, $actor->user->id, [
            'document_type' => 'quality_passport', 'title' => 'Электронный паспорт',
            'initial_version' => [
                'version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('original.pdf', 'electronic original'),
                'file_kind' => 'electronic_original', 'signature_file' => UploadedFile::fake()->createWithContent('original.sig', 'detached signature'),
            ],
        ]);

        self::assertSame('paper_scan', $scan->versions->first()->metadata['file_kind']);
        self::assertSame('electronic_original', $electronic->versions->first()->metadata['file_kind']);
        self::assertSame(hash('sha256', 'detached signature'), $electronic->versions->first()->metadata['signature_hash']);
        self::assertNotEmpty($electronic->versions->first()->metadata['signature_file_url']);
    }

    public function test_act_composition_requires_additional_participants_from_the_object_list(): void
    {
        [$actor, $set] = $this->fixture();
        $list = ExecutiveDocumentApprovedList::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $set->project_id,
            'revision' => 1, 'approved_by_party' => 'Технический заказчик', 'approved_at' => '2026-09-24',
            'file_url' => 'org-'.$actor->organization->id.'/approved-list.pdf', 'file_hash' => str_repeat('a', 64),
            'original_name' => 'approved-list.pdf', 'uploaded_by' => $actor->user->id,
            'items' => [['key' => 'geodetic-act', 'profile_type' => 'geodetic_base_acceptance_act',
                'title' => 'Акт геодезической основы', 'stage' => 'document_review',
                'conditions' => ['designer_supervision' => true, 'separate_executor' => true]]],
        ]);
        $set->forceFill(['approved_list_id' => $list->id])->save();
        $signer = static fn (string $role): array => [
            'role' => $role, 'name' => 'Иванов И.И.', 'organization' => 'Строительная организация', 'authority_document' => 'Доверенность №1',
        ];
        $data = [
            'document_type' => 'geodetic_base_acceptance_act', 'title' => 'Акт геодезической основы',
            'profile_data' => ['act_number' => 'ГО-2', 'geodetic_base_description' => 'Реперы', 'base_acceptance_documents' => 'Схема'],
            'signatories' => array_map($signer, ['developer_control_representative', 'construction_representative', 'contractor_control_representative']),
        ];
        $composer = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPreparationService::class);
        try {
            $composer->compose($set, $actor->user->id, $data);
            self::fail('Условные участники акта должны быть обязательны.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, $set->documents()->count());
        }
        $data['signatories'][] = $signer('designer_representative');
        $data['signatories'][] = $signer('geodetic_base_executor');
        $document = $composer->compose($set, $actor->user->id, $data);
        self::assertSame('generated', $document->metadata['capture_mode']);
    }

    public function test_passport_registration_does_not_require_a_control_event_and_freezes_profile_identity(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $document = $service->addDocument($set, $actor->user->id, $this->passport());
        self::assertSame('quality_passport', $document->document_type->value);
        self::assertSame('external_manual_review', $document->versions->first()->basis_snapshot['profile']['profile_mode'] ?? null);
        self::assertNotEmpty($document->versions->first()->basis_snapshot['profile']['profile_revision'] ?? null);
        self::assertSame(0, $document->relations()->count());
    }

    public function test_uploaded_control_does_not_require_retyping_a_delivery_relation(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $data = $this->passport();
        $data['document_type'] = 'incoming_batch_control';
        $data['profile_data'] = ['control_number' => 'ВК-1', 'received_at' => '2026-09-20', 'checked_at' => '2026-09-20', 'material_name' => 'Бетон', 'supplier' => 'Завод', 'batch_details' => 'Партия 1', 'quantity' => '10', 'control_result' => 'accepted'];
        $document = $service->addDocument($set, $actor->user->id, $data);
        self::assertSame('draft', $document->status->value);
        self::assertSame(0, $document->relations()->count());
    }

    public function test_invalid_profile_dates_are_rejected_in_service_calls(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $data = $this->passport();
        $data['profile_data']['quality_document_date'] = '2026-02-30';
        $this->expectException(\DomainException::class);
        $service->addDocument($set, $actor->user->id, $data);
    }

    public function test_foreign_actor_cannot_create_a_card_without_an_initial_file(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $data = $this->passport();
        unset($data['initial_version']);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $service->addDocument($set, $foreign->user->id, $data);
    }

    public function test_one_quality_document_supports_two_own_deliveries_but_not_a_foreign_delivery(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $unit = \App\Models\MeasurementUnit::query()->create(['organization_id' => $actor->organization->id, 'name' => 'Кубометр', 'short_name' => 'м3', 'type' => 'material']);
        $material = \App\Models\Material::query()->create(['organization_id' => $actor->organization->id, 'name' => 'Бетон', 'measurement_unit_id' => $unit->id, 'is_active' => true]);
        $passportData = $this->passport();
        $passportData['relations'] = [['relation_type' => 'material_reference', 'target_type' => 'material', 'target_id' => $material->id]];
        $passport = $service->addDocument($set, $actor->user->id, $passportData);
        foreach ([1, 2] as $number) {
            $delivery = \App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery::query()->create([
                'organization_id' => $actor->organization->id, 'project_id' => $set->project_id, 'material_id' => $material->id,
                'status' => 'accepted', 'accepted_quantity' => 10, 'delivered_at' => '2026-09-20',
            ]);
            $data = $this->passport();
            $data['document_type'] = 'incoming_batch_control';
            $data['profile_data'] = ['control_number' => 'ВК-'.$number, 'received_at' => '2026-09-20', 'checked_at' => '2026-09-20', 'material_name' => 'Бетон', 'supplier' => 'Завод', 'batch_details' => 'Партия '.$number, 'quantity' => '10', 'control_result' => 'accepted'];
            $data['relations'] = [
                ['relation_type' => 'material_delivery', 'target_type' => 'project_material_delivery', 'target_id' => $delivery->id],
                ['relation_type' => 'quality_passport', 'target_type' => 'quality_passport', 'target_id' => $passport->id],
            ];
            $control = $service->addDocument($set, $actor->user->id, $data);
            self::assertSame('м3', $control->versions->first()->basis_snapshot['material_delivery']['unit'] ?? null);
            self::assertSame($passport->versions->first()->id, $control->versions->first()->basis_snapshot['material_delivery']['quality_documents'][0]['version_id']);
            self::assertSame($passport->id, $control->relations->firstWhere('relation_type', 'quality_passport')->target_id);
        }
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $references = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveMaterialProfileGuard::class)->deliveryReferences($actor->organization->id, $set->project_id);
        self::assertCount(2, $references);
        self::assertSame('м3', $references[0]['unit']);
        $delivery->update(['organization_id' => $foreign->organization->id, 'project_id' => $foreignProject->id]);
        $this->expectException(\DomainException::class);
        $service->addDocument($set, $actor->user->id, $data);
    }

    public function test_measurements_require_units_and_legacy_classification_is_not_rewritten(): void
    {
        [$actor, $set, $service] = $this->fixture();
        $registry = app(\App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry::class);
        self::assertSame('incoming_control_document', $registry->require('incoming_control_document')['legacy_type']);
        self::assertSame('external_manual_review', $registry->require('work_journal')['profile_mode']);
        $data = $this->passport();
        $data['document_type'] = 'system_test_act';
        $data['profile_data'] = ['measured_value' => '0.6'];
        try { $service->addDocument($set, $actor->user->id, $data); self::fail('Measurement without unit accepted'); } catch (\DomainException) {}
        $data['profile_data']['measurement_unit'] = 'МПа';
        $document = $service->addDocument($set, $actor->user->id, $data);
        self::assertSame('МПа', $document->versions->first()->profile_snapshot['measurement_unit']);
    }

    private function passport(): array
    {
        return ['document_type' => 'quality_passport', 'title' => 'Паспорт бетона', 'profile_data' => [
            'document_number' => 'П-1', 'quality_document_kind' => 'passport', 'material_name' => 'Бетон',
            'manufacturer' => 'Завод', 'quality_document_date' => '2026-09-20', 'quality_document_details' => 'Бетон В25',
        ], 'initial_version' => ['version_number' => '1', 'file' => UploadedFile::fake()->createWithContent('passport.pdf', 'passport')]];
    }

    private function fixture(): array
    {
        Storage::fake('s3');
        foreach ([\App\Domain\Authorization\Services\ModulePermissionChecker::class, \App\Domain\Authorization\Services\PermissionResolver::class, \App\Domain\Authorization\Services\AuthorizationService::class] as $service) $this->app->forgetInstance($service);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $actor = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $set = ExecutiveDocumentSet::query()->create(['organization_id' => $actor->organization->id, 'project_id' => $project->id, 'created_by' => $actor->user->id, 'set_number' => 'PROFILE-1', 'title' => 'Материалы', 'status' => 'draft']);
        return [$actor, $set, app(ExecutiveDocumentationService::class)];
    }
}
