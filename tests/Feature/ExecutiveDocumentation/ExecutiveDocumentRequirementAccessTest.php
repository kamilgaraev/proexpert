<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentRequirementAccessTest extends TestCase
{
    public function test_conditions_can_be_resolved_without_replacing_the_requirement_or_its_evidence(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, $this->requirement(), $context->user, $authorization);
        $oldProfile = $requirement->fresh()->rule_snapshot['profile'];
        $requirement->update(['evidence' => [['version_id' => 123456, 'coverage' => ['project_id' => $set->project_id]]]]);
        $updated = $service->updateConditions($requirement, ['designer_supervision' => false, 'separate_executor' => false], 'Работы выполняются собственными силами; проектировщик не привлечён', $context->user, $authorization, 1);
        self::assertSame($requirement->id, $updated->id);
        self::assertSame($requirement->fresh()->evidence, $updated->evidence);
        self::assertSame($oldProfile, $updated->rule_snapshot['profile']);
        self::assertSame([], $updated->rule_snapshot['unresolved_conditions']);
        self::assertSame(2, $updated->revision);
        self::assertSame('conditions_changed', \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('requirement_id', $requirement->id)->orderByDesc('id')->value('action'));
        $replayed = $service->updateConditions($requirement, ['separate_executor' => false, 'designer_supervision' => false], 'Работы выполняются собственными силами; проектировщик не привлечён', $context->user, $authorization, 1);
        self::assertSame(2, $replayed->revision);
        self::assertSame(2, \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('requirement_id', $requirement->id)->count());
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->updateConditions($requirement, ['designer_supervision' => true, 'separate_executor' => false], 'Проектировщик привлечён новым договором', $context->user, $authorization, 1);
    }

    public function test_conditions_update_requires_approval_permission_and_a_draft_set(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, $this->requirement(), $context->user, $authorization);
        $limited = \Mockery::mock(AuthorizationService::class);
        $limited->shouldReceive('can')->andReturnUsing(static fn ($actor, $permission): bool => $permission === 'executive-documentation.edit');
        try {
            $service->updateConditions($requirement, ['designer_supervision' => false], 'Решение по договору', $context->user, $limited, 1);
            self::fail('Approval permission is required');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        $set->update(['status' => 'transmitted']);
        $this->expectException(ValidationException::class);
        $service->updateConditions($requirement, ['designer_supervision' => false], 'Решение по договору', $context->user, $authorization, 1);
    }

    public function test_act_requirement_freezes_server_signatory_rules_and_explicit_conditions(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $requirement = app(ExecutiveDocumentRequirementsService::class)->create($set, array_replace($this->requirement(), [
            'conditions' => ['designer_supervision' => true, 'separate_executor' => false],
        ]), $context->user, $authorization);
        self::assertArrayHasKey('developer_control_representative', $requirement->rule_snapshot['required_signatories']);
        self::assertArrayHasKey('designer_representative', $requirement->rule_snapshot['required_signatories']);
        self::assertArrayNotHasKey('direct_work_executor', $requirement->rule_snapshot['required_signatories']);
        self::assertSame($context->user->id, $requirement->rule_snapshot['conditions_decided_by']);
        self::assertSame([], $requirement->rule_snapshot['unresolved_conditions']);
    }

    public function test_unresolved_normative_conditions_are_not_silently_treated_as_false(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, $this->requirement(), $context->user, $authorization);
        $readiness = $service->readiness($set);
        self::assertSame('normative_conditions_unresolved', $readiness['blockers'][0]['code']);
        self::assertSame(['designer_supervision', 'separate_executor'], $readiness['blockers'][0]['conditions']);
        self::assertSame(['type' => 'requirement', 'id' => $requirement->id], $readiness['blockers'][0]['target']);
    }

    public function test_edit_only_actor_cannot_decide_normative_conditions(): void
    {
        [$context, $set] = $this->context(true);
        $limited = \Mockery::mock(AuthorizationService::class);
        $limited->shouldReceive('can')->andReturnUsing(static fn ($actor, $permission): bool => $permission === 'executive-documentation.edit');
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(403);
        app(ExecutiveDocumentRequirementsService::class)->create($set, array_replace($this->requirement(), [
            'conditions' => ['designer_supervision' => false, 'separate_executor' => false],
        ]), $context->user, $limited);
    }

    public function test_invalid_evidence_explains_the_missing_field_and_links_to_its_document_version(): void
    {
        [$context, $document] = $this->volumeDocument();
        $version = $this->uploadVolumeVersion($context, $document);
        $version->update(['status' => 'approved', 'profile_snapshot' => []]);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($document->documentSet, array_replace($this->requirement(), ['profile_type' => 'working_drawing_set']), $context->user, app(AuthorizationService::class));
        $requirement->update(['evidence' => [['version_id' => $version->id, 'coverage' => $requirement->coverage_scope]]]);
        $readiness = $service->readiness($document->documentSet);
        self::assertFalse($readiness['ready']);
        self::assertSame(0, $readiness['requirements_satisfied']);
        self::assertSame(1, $readiness['missing_requirements']);
        self::assertSame('invalid_document_evidence', $readiness['blockers'][0]['code']);
        self::assertSame('required_field_missing', $readiness['blockers'][0]['issues'][0]['code']);
        self::assertSame(['type' => 'executive_document', 'id' => $document->id, 'version_id' => $version->id], $readiness['blockers'][0]['target']);
    }

    public function test_composition_replacement_rejects_a_stale_revision_without_losing_decisions(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, $this->requirement(), $context->user, $authorization);
        $revision = (int) \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('document_set_id', $set->id)->max('id');
        $service->markNotApplicable($requirement, $context->user, 'Работа исключена из проекта', $authorization);
        try {
            $service->replaceForSet($set, [$this->requirement()], $context->user, $authorization, $revision, 'stale-composition');
            self::fail('Stale composition must not overwrite a decision');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        self::assertNull($requirement->fresh()->superseded_at);
        self::assertSame('not_applicable', $requirement->fresh()->applicability);
    }

    public function test_composition_replacement_replays_without_new_history_and_rejects_changed_payload(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $service->replaceForSet($set, [$this->requirement()], $context->user, $authorization, 0, 'composition-replay');
        $events = \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('document_set_id', $set->id)->count();
        $service->replaceForSet($set, [$this->requirement()], $context->user, $authorization, 0, 'composition-replay');
        self::assertSame($events, \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('document_set_id', $set->id)->count());
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->replaceForSet($set, [array_replace($this->requirement(), ['title' => 'Другой состав'])], $context->user, $authorization, 0, 'composition-replay');
    }

    public function test_conditional_requirement_needs_a_recorded_applicability_decision(): void
    {
        [$context, $document] = $this->volumeDocument();
        $version = $this->uploadVolumeVersion($context, $document);
        $version->update(['status' => 'approved']);
        $document->update(['status' => 'approved']);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $authorization = app(AuthorizationService::class);
        $requirement = $service->create($document->documentSet, array_replace($this->requirement(), [
            'profile_type' => 'working_drawing_set', 'applicability' => 'conditional',
        ]), $context->user, $authorization);
        $requirement = $service->attachEvidence($requirement, $version->id, $requirement->coverage_scope, $context->user, $authorization);
        $readiness = $service->readiness($document->documentSet);
        self::assertFalse($readiness['ready']);
        self::assertSame('applicability_unresolved', $readiness['blockers'][0]['code']);
        $decided = $service->markApplicable($requirement, $context->user, 'Комплект требуется по утверждённому перечню раздела АР', $authorization);
        self::assertSame('required', $decided->applicability);
        self::assertSame('Комплект требуется по утверждённому перечню раздела АР', $decided->applicability_reason);
        self::assertTrue($service->readiness($document->documentSet)['ready']);
    }

    public function test_requirement_history_cannot_be_rewritten_in_the_database(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $requirement = app(ExecutiveDocumentRequirementsService::class)->create($set, $this->requirement(), $context->user, $authorization);
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($requirement): void {
                \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('requirement_id', $requirement->id)->update(['action' => 'rewritten']);
            });
            self::fail('History must be immutable');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('executive_requirement_event_immutable', $exception->getMessage());
        }
        self::assertSame('created', \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('requirement_id', $requirement->id)->value('action'));
    }

    public function test_declared_partial_volume_only_satisfies_the_matching_requirement(): void
    {
        [$context, $document, $work, $unitId] = $this->volumeDocument();
        $document->update(['metadata' => ['coverage' => ['quantity' => '80', 'measurement_unit_id' => $unitId]]]);
        $version = $this->uploadVolumeVersion($context, $document);
        $version->update(['status' => 'approved']);
        $document->update(['status' => 'approved']);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $authorization = app(AuthorizationService::class);
        $scope = ['project_id' => $work->project_id, 'completed_work_id' => $work->id, 'quantity' => '80.000000', 'measurement_unit_id' => $unitId];
        $requirement = $service->create($document->documentSet, array_replace($this->requirement(), [
            'profile_type' => 'working_drawing_set', 'coverage_scope' => $scope,
        ]), $context->user, $authorization);
        self::assertSame('80', $requirement->coverage_scope['quantity']);
        $service->attachEvidence($requirement, $version->id, $requirement->coverage_scope, $context->user, $authorization);
        self::assertTrue($service->readiness($document->documentSet)['ready']);
        $replayed = $service->attachEvidence($requirement, $version->id, array_replace(array_reverse($requirement->coverage_scope, true), ['project_id' => (string) $work->project_id, 'quantity' => '80.000000']), $context->user, $authorization, 1);
        self::assertSame(2, $replayed->revision);
        $smaller = $service->create($document->documentSet, array_replace($this->requirement(), [
            'profile_type' => 'working_drawing_set', 'coverage_scope' => array_replace($scope, ['quantity' => '60']),
        ]), $context->user, $authorization);
        try {
            $service->attachEvidence($smaller, $version->id, array_replace($smaller->coverage_scope, ['quantity' => '90']), $context->user, $authorization);
            self::fail('The attachment cannot claim more than the immutable document coverage');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('evidence', $exception->errors());
        }
        $service->attachEvidence($smaller, $version->id, $smaller->coverage_scope, $context->user, $authorization);
        self::assertTrue($service->readiness($document->documentSet)['ready']);
        $larger = $service->create($document->documentSet, array_replace($this->requirement(), [
            'profile_type' => 'working_drawing_set', 'coverage_scope' => array_replace($scope, ['quantity' => '100']),
        ]), $context->user, $authorization);
        $this->expectException(ValidationException::class);
        $service->attachEvidence($larger, $version->id, $larger->coverage_scope, $context->user, $authorization);
    }

    public function test_readiness_batches_evidence_queries_for_a_large_composition(): void
    {
        [$context, $document, $work, $unitId] = $this->volumeDocument();
        $document->update(['metadata' => ['coverage' => ['quantity' => '80', 'measurement_unit_id' => $unitId]]]);
        $version = $this->uploadVolumeVersion($context, $document);
        $version->update(['status' => 'approved']);
        $document->update(['status' => 'approved']);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $authorization = app(AuthorizationService::class);
        for ($i = 0; $i < 12; $i++) {
            $requirement = $service->create($document->documentSet, array_replace($this->requirement(), [
                'profile_type' => 'working_drawing_set', 'requirement_key' => 'drawing-'.$i,
                'coverage_scope' => ['project_id' => $work->project_id, 'completed_work_id' => $work->id, 'quantity' => '80', 'measurement_unit_id' => $unitId],
            ]), $context->user, $authorization);
            $service->attachEvidence($requirement, $version->id, $requirement->coverage_scope, $context->user, $authorization);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            $readiness = $service->readiness($document->documentSet);
            self::assertTrue($readiness['ready']);
            self::assertSame(12, $readiness['requirements_satisfied']);
            self::assertLessThanOrEqual(6, count(\Illuminate\Support\Facades\DB::getQueryLog()));
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }
    }

    public function test_version_freezes_explicit_partial_coverage_without_inflating_it_to_the_source_fact(): void
    {
        [$context, $document, $work, $unitId] = $this->volumeDocument();
        $document->update(['metadata' => ['coverage' => ['quantity' => '80.0000', 'measurement_unit_id' => $unitId]]]);
        $version = $this->uploadVolumeVersion($context, $document);
        self::assertSame('80', $version->basis_snapshot['coverage']['quantity']);
        self::assertSame('100.0000', $version->basis_snapshot['coverage']['source_quantity']);
        $work->update(['quantity' => '120', 'completed_quantity' => '120']);
        self::assertSame('80', $version->fresh()->basis_snapshot['coverage']['quantity']);
    }

    public function test_version_rejects_declared_coverage_above_the_fact_or_in_another_unit(): void
    {
        [$context, $document, $work, $unitId] = $this->volumeDocument();
        foreach ([['quantity' => '101', 'measurement_unit_id' => $unitId], ['quantity' => '80', 'measurement_unit_id' => $unitId + 1000]] as $declaration) {
            $document->update(['metadata' => ['coverage' => $declaration]]);
            try {
                $this->uploadVolumeVersion($context, $document);
                self::fail('Invalid coverage was accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('metadata.coverage', $exception->errors());
            }
        }
        self::assertSame(0, $document->versions()->count());
    }

    public function test_source_quantity_does_not_imply_declared_document_coverage(): void
    {
        [$context, $document] = $this->volumeDocument();
        $version = $this->uploadVolumeVersion($context, $document);
        self::assertArrayNotHasKey('quantity', $version->basis_snapshot['coverage']);
        self::assertSame('100.0000', $version->basis_snapshot['coverage']['source_quantity']);
    }

    private function volumeDocument(): array
    {
        [$context, $set] = $this->context(true);
        \Illuminate\Support\Facades\Storage::fake('s3');
        $unitId = (int) \App\Models\MeasurementUnit::query()->value('id');
        $workType = \App\Models\WorkType::query()->create([
            'organization_id' => $set->organization_id, 'name' => 'Армирование', 'code' => 'VOLUME-T16',
            'measurement_unit_id' => $unitId, 'is_active' => true,
        ]);
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
            'work_type_id' => $workType->id, 'user_id' => $context->user->id,
            'quantity' => '100', 'completed_quantity' => '100', 'completion_date' => '2026-09-20',
            'status' => 'confirmed', 'work_origin_type' => 'manual', 'planning_status' => 'planned',
        ]);
        $document = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
            'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set', 'title' => 'Документ участка', 'status' => 'draft',
            'completed_work_id' => $work->id,
            'profile_data' => [
                'drawing_set_code' => 'РД-1', 'drawing_section' => 'АР', 'sheet_list' => ['1'],
                'compliance_mark' => 'Соответствует', 'responsible_person' => 'Инженер',
                'authority_document' => 'Приказ 1', 'drawing_set_status' => 'accepted',
            ],
        ]);
        return [$context, $document, $work, $unitId];
    }

    private function uploadVolumeVersion($context, $document)
    {
        return app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService::class)->addVersion($document, $context->user->id, [
            'version_number' => '1', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('volume.pdf', 'volume'),
        ]);
    }

    public function test_edit_permission_cannot_waive_a_requirement_by_replacing_the_composition(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $service->create($set, $this->requirement(), $context->user, $authorization);
        $limited = \Mockery::mock(AuthorizationService::class);
        $limited->shouldReceive('can')->andReturnUsing(static fn ($actor, $permission): bool => $permission === 'executive-documentation.edit');
        $this->expectException(BusinessLogicException::class);
        $service->replaceForSet($set, [array_replace($this->requirement(), ['profile_type' => 'working_drawing_set'])], $context->user, $limited);
    }

    public function test_coverage_scope_cannot_name_another_project(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $foreignProject = Project::factory()->create();
        $this->expectException(ValidationException::class);
        app(ExecutiveDocumentRequirementsService::class)->create($set, array_replace($this->requirement(), [
            'coverage_scope' => ['project_id' => $foreignProject->id],
        ]), $context->user, $authorization);
    }

    public function test_transmission_rechecks_missing_requirements_and_freezes_the_approved_composition(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $requirements = app(ExecutiveDocumentRequirementsService::class);
        $profile = [
            'drawing_set_code' => 'РД-1', 'drawing_section' => 'АР', 'sheet_list' => ['1'],
            'compliance_mark' => 'Соответствует', 'responsible_person' => 'Инженер',
            'authority_document' => 'Приказ 1', 'drawing_set_status' => 'accepted',
        ];
        $document = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
            'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set', 'title' => 'Чертежи', 'status' => 'approved', 'profile_data' => $profile,
        ]);
        $drawing = $requirements->create($set, array_replace($this->requirement(), [
            'profile_type' => 'working_drawing_set', 'coverage_scope' => ['project_id' => $set->project_id],
        ]), $context->user, $authorization);
        $protocol = $requirements->create($set, array_replace($this->requirement(), [
            'profile_type' => 'system_test_act', 'coverage_scope' => ['project_id' => $set->project_id],
        ]), $context->user, $authorization);
        $version = $document->versions()->create([
            'organization_id' => $set->organization_id, 'uploaded_by' => $context->user->id,
            'version_number' => '1', 'file_url' => 'test/rd.pdf', 'content_hash' => hash('sha256', 'РД'), 'status' => 'approved',
            'profile_snapshot' => $profile,
            'basis_snapshot' => ['profile' => $drawing->rule_snapshot['profile'], 'coverage' => ['project_id' => $set->project_id]],
        ]);
        $requirements->attachEvidence($drawing, $version->id, ['project_id' => $set->project_id], $context->user, $authorization);
        $service = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService::class);
        $command = ['transmittal_number' => 'Т16-1', 'operation_key' => 't16-transmit'];
        try {
            $service->transmit($set, $context->user->id, $command);
            self::fail('Missing protocol must block the command, not only the preview');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('requirements', $exception->errors());
        }
        self::assertSame(0, $set->transmittal()->count());
        $requirements->markNotApplicable($protocol, $context->user, 'Испытания не предусмотрены для этого раздела РД', $authorization);
        $transmittal = $service->transmit($set->fresh(), $context->user->id, $command)->transmittal;
        self::assertCount(2, $transmittal->manifest['requirements']);
        self::assertSame('not_applicable', $transmittal->manifest['requirements'][1]['applicability']);
        self::assertSame($version->id, $transmittal->manifest['requirements'][0]['evidence'][0]['version_id']);
        self::assertSame($transmittal->id, $service->transmit($set->fresh(), $context->user->id, $command)->transmittal->id);
    }

    public function test_approved_file_without_required_profile_fields_cannot_cover_a_requirement(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, array_replace($this->requirement(), [
            'profile_type' => 'working_drawing_set', 'coverage_scope' => ['project_id' => $set->project_id],
        ]), $context->user, $authorization);
        $document = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
            'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set', 'title' => 'Неполный комплект', 'status' => 'approved',
        ]);
        $version = $document->versions()->create([
            'organization_id' => $set->organization_id, 'uploaded_by' => $context->user->id,
            'version_number' => '1', 'file_url' => 'test/drawings.pdf', 'content_hash' => hash('sha256', 'drawings'), 'status' => 'approved',
            'profile_snapshot' => [],
            'basis_snapshot' => ['profile' => $requirement->rule_snapshot['profile'], 'coverage' => ['project_id' => $set->project_id]],
        ]);
        $this->expectException(ValidationException::class);
        $service->attachEvidence($requirement, $version->id, ['project_id' => $set->project_id], $context->user, $authorization);
    }

    public function test_stale_requirement_cannot_overwrite_a_more_recent_decision(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, $this->requirement(), $context->user, $authorization);
        $service->markNotApplicable($requirement, $context->user, 'Работа исключена проектом', $authorization);
        try {
            $service->markNotApplicable($requirement, $context->user, 'Другая причина из старой формы', $authorization);
            self::fail('Stale decision must not overwrite current history');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        self::assertSame('Работа исключена проектом', $requirement->fresh()->not_applicable_reason);
    }

    public function test_not_applicable_requires_approval_permission_and_preserves_the_previous_decision(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $service = app(ExecutiveDocumentRequirementsService::class);
        $requirement = $service->create($set, $this->requirement(), $context->user, $authorization);
        $limited = \Mockery::mock(AuthorizationService::class);
        $limited->shouldReceive('can')->andReturnUsing(static fn ($actor, $permission): bool => $permission === 'executive-documentation.edit');
        try {
            $service->markNotApplicable($requirement, $context->user, 'Работа исключена проектом', $limited);
            self::fail('Editing permission must not waive a requirement');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        $updated = $service->markNotApplicable($requirement, $context->user, 'Работа исключена проектом', $authorization);
        $history = \Illuminate\Support\Facades\DB::table('executive_document_requirement_events')->where('requirement_id', $requirement->id)->orderBy('id')->get();
        self::assertCount(2, $history);
        self::assertSame('created', $history[0]->action);
        self::assertSame('not_applicable', $history[1]->action);
        self::assertSame('required', json_decode($history[1]->before_snapshot, true)['applicability']);
        self::assertSame($context->user->id, $history[1]->actor_id);
        self::assertSame('not_applicable', $updated->applicability);
    }

    public function test_uploaded_version_carries_server_owned_location_coverage(): void
    {
        [$context, $set] = $this->context(true);
        \Illuminate\Support\Facades\Storage::fake('s3');
        $location = \App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation::query()->create([
            'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
            'location_type' => 'zone', 'name' => 'Секция А', 'code' => 'A', 'level' => 0,
        ]);
        $document = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
            'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set', 'title' => 'Чертежи секции', 'status' => 'draft',
            'profile_data' => ['drawing_set_code' => 'РД-А'],
            'metadata' => ['project_location_id' => $location->id],
        ]);
        $version = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService::class)
            ->addVersion($document, $context->user->id, [
                'version_number' => '1',
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('drawing.pdf', 'drawing'),
            ]);
        self::assertSame(['project_id' => $set->project_id, 'project_location_id' => $location->id], $version->basis_snapshot['coverage']);
        $document->update(['metadata' => []]);
        self::assertSame($location->id, $version->fresh()->basis_snapshot['coverage']['project_location_id']);
    }

    public function test_direct_creation_rejects_unassigned_project_even_when_permission_is_granted(): void
    {
        [$context, $set, $authorization] = $this->context(false);
        $this->expectException(BusinessLogicException::class);
        app(ExecutiveDocumentRequirementsService::class)->create($set, $this->requirement(), $context->user, $authorization);
    }

    public function test_direct_creation_reloads_set_and_rejects_transmitted_state(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        ExecutiveDocumentSet::query()->whereKey($set->id)->update(['status' => 'transmitted']);
        $this->expectException(ValidationException::class);
        app(ExecutiveDocumentRequirementsService::class)->create($set, $this->requirement(), $context->user, $authorization);
    }

    public function test_unknown_profile_cannot_be_used_as_a_requirement(): void
    {
        [$context, $set, $authorization] = $this->context(true);
        $this->expectException(ValidationException::class);
        app(ExecutiveDocumentRequirementsService::class)->create($set, array_replace($this->requirement(), ['profile_type' => 'invented_form']), $context->user, $authorization);
    }

    private function context(bool $assigned): array
    {
        $context = AdminApiTestContext::create();
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        if ($assigned) {
            $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        }
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'T16-ACCESS', 'title' => 'T16', 'status' => 'draft',
        ]);
        $authorization = $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });
        return [$context, $set, $authorization];
    }

    private function requirement(): array
    {
        return ['profile_type' => 'hidden_work_act', 'source' => 'Перечень проекта', 'source_revision' => '1'];
    }
}
