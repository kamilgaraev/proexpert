<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Mockery\MockInterface;

final class ExecutiveDocumentRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_expected_protocol_keeps_set_not_ready_when_act_exists(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'SET-T16', 'title' => 'T16', 'status' => 'draft',
        ]);
        $workType = \App\Models\WorkType::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Армирование', 'code' => 'T16-REBAR',
            'measurement_unit_id' => \App\Models\MeasurementUnit::query()->value('id'), 'is_active' => true,
        ]);
        $journal = \App\Models\ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'name' => 'Журнал Т16', 'journal_number' => 'Т16', 'start_date' => '2026-09-20',
            'status' => 'active', 'created_by_user_id' => $context->user->id,
        ]);
        $entry = $journal->entries()->create([
            'entry_date' => '2026-09-20', 'entry_number' => 1, 'work_description' => 'Армирование плиты',
            'status' => 'draft', 'created_by_user_id' => $context->user->id,
        ]);
        $act = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'hidden_work_act', 'title' => 'АОСР', 'status' => 'approved',
            'work_type_id' => $workType->id, 'journal_entry_id' => $entry->id,
        ]);
        $act->versions()->create([
            'organization_id' => $context->organization->id, 'uploaded_by' => $context->user->id,
            'version_number' => '1.0', 'file_url' => 's3://t16/act.pdf', 'status' => 'approved',
            'content_hash' => hash('sha256', 'АОСР'),
            'profile_snapshot' => [
                'act_number' => '1', 'presented_works' => 'Армирование плиты', 'started_at' => '2026-09-19',
                'finished_at' => '2026-09-20', 'next_works_permission' => 'Разрешено бетонирование',
            ],
            'basis_snapshot' => [
                'profile' => app(\App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry::class)->require('hidden_work_act'),
                'coverage' => ['project_id' => $project->id],
                'journal_entry_id' => $entry->id, 'document' => [
                    'work_type_id' => $workType->id,
                    'signatories' => array_map(static fn (string $role): array => [
                        'role' => $role, 'name' => 'Представитель', 'organization' => 'Участник строительства', 'authority_document' => 'Приказ 1',
                    ], ['developer_control_representative', 'construction_representative', 'contractor_control_representative']),
                ],
            ],
        ]);

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });

        $service = app(ExecutiveDocumentRequirementsService::class);
        $service->replaceForSet($set, [
            ['requirement_key' => 'aosr', 'title' => 'АОСР', 'profile_type' => 'hidden_work_act', 'stage' => 'document_review', 'source' => 'ПТО: комплект работ', 'source_revision' => '2026-09-20.1', 'coverage_scope' => ['project_id' => $project->id], 'conditions' => ['designer_supervision' => false, 'separate_executor' => false]],
            ['requirement_key' => 'protocol', 'title' => 'Протокол испытаний', 'profile_type' => 'system_test_act', 'stage' => 'document_review', 'source' => 'ПТО: комплект работ', 'source_revision' => '2026-09-20.1', 'coverage_scope' => ['project_id' => $project->id]],
        ], $context->user, app(AuthorizationService::class));

        $aosrRequirement = $set->requirements()->where('requirement_key', 'aosr')->firstOrFail();
        $service->attachEvidence($aosrRequirement, $act->versions()->firstOrFail()->id, ['project_id' => $project->id], $context->user, app(AuthorizationService::class));

        $readiness = $service->readiness($set->fresh());

        self::assertFalse($readiness['ready']);
        self::assertSame(2, $readiness['requirements_total']);
        self::assertSame(1, $readiness['missing_requirements']);
        self::assertSame('missing_expected_document', $readiness['blockers'][0]['code']);
    }
}
