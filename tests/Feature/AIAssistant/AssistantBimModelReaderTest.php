<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetBimModelElementsTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantBimModelReaderTest extends TestCase
{
    use RefreshDatabase;

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private AssistantBimModelReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = AssistantRealAuthorizationFixture::create();
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'], 'design-management' => ['design-management.view', 'design-management.models.view']]]);
        $this->fixture->member->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
        $this->reader = app(AssistantBimModelReader::class);
    }

    public function test_natural_name_finds_cyrillic_and_transliterated_models_without_rag_and_keeps_versions_separate(): void
    {
        $old = $this->model('garazhnaya', 'garazhnaya.ifc');
        $new = $this->model('Гаражная новая', 'гаражная.ifc');
        $this->element($old, 'IFCSLAB', 'Перекрытие первого этажа');
        $this->element($new, 'IFCSLAB', 'Перекрытие второго этажа');
        $this->element($new, 'IFCSLAB', 'Перекрытие третьего этажа');
        $this->element($new, 'IFCPLATE', 'Стальная пластина');
        self::assertSame(0, DB::table('ai_rag_sources')->where('entity_type', 'design_ifc_model_element')->count());
        $result = $this->read(['model_query' => 'гаражной', 'element_query' => 'перекрытия']);
        self::assertCount(2, $result['models']);
        self::assertSame([1, 2], array_column($result['models'], 'total'));
        self::assertStringContainsString('отдельно по каждой', $result['server_formatted_answer']);
        self::assertSame(['IFCSLAB', 'IFCSLAB'], array_column($result['models'][1]['elements'], 'category'));
        self::assertSame('verified', $this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id)['validation_status']);
        foreach ($result['source_refs'] as $reference) {
            self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, [$reference]));
        }
    }

    public function test_category_absence_is_not_reported_as_physical_absence_and_name_matches_other_ifc_classes(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $this->element($version, 'IFCPLATE', 'Пластина');
        $result = $this->read(['element_query' => 'перекрытия']);
        self::assertSame(0, $result['models'][0]['total']);
        self::assertStringContainsString('не доказывает отсутствие', $result['server_formatted_answer']);
        self::assertStringContainsString('Пластины: 1', $result['server_formatted_answer']);
        $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'Перекрытие сборное');
        self::assertSame(1, $this->read(['element_query' => 'перекрытия'])['models'][0]['total']);
    }

    public function test_current_version_and_explicit_historical_version_have_bounded_pages(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        for ($i = 0; $i < 23; $i++) {
            $this->element($version, 'IFCCOLUMN', 'Колонна '.$i);
        }
        $first = $this->read(['element_query' => 'колонны']);
        self::assertCount(20, $first['models'][0]['elements']);
        self::assertSame(23, $first['models'][0]['total']);
        self::assertTrue($first['models'][0]['has_more']);
        $second = $this->read(['element_query' => 'колонны', 'offset' => 20]);
        self::assertCount(3, $second['models'][0]['elements']);
        self::assertFalse($second['models'][0]['has_more']);
        $version->updateQuietly(['is_current' => false]);
        self::assertSame('empty', $this->read([])['status']);
        self::assertSame(23, $this->read(['version_id' => $version->id, 'element_query' => 'IFCCOLUMN'])['models'][0]['total']);
    }

    public function test_hidden_projects_foreign_organizations_and_inconsistent_element_parents_never_contribute(): void
    {
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        $this->element($this->model('Гаражная скрытая', 'гаражная.ifc', $hidden), 'IFCSLAB', 'Скрытое');
        $this->element($this->model('Гаражная чужая', 'гаражная.ifc', $foreign), 'IFCSLAB', 'Чужое');
        $visible = $this->model('Гаражная', 'гаражная.ifc');
        $this->element($visible, 'IFCSLAB', 'Доступное');
        $bad = $this->element($visible, 'IFCSLAB', 'Неверный родитель');
        $bad->updateQuietly(['project_id' => $hidden->id]);
        $result = $this->read(['project_id' => null, 'element_query' => 'перекрытия']);
        self::assertCount(1, $result['models']);
        self::assertSame(1, $result['models'][0]['total']);
        self::assertStringNotContainsString('Скрытое', $result['server_formatted_answer']);
        self::assertStringNotContainsString('Чужое', $result['server_formatted_answer']);
        self::assertStringNotContainsString('Неверный родитель', $result['server_formatted_answer']);
        $this->expectException(AccessDeniedHttpException::class);
        $this->read(['project_id' => $hidden->id]);
    }

    public function test_explicit_version_must_belong_to_selected_project(): void
    {
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $version = $this->model('Гаражная', 'гаражная.ifc', $hidden);
        $this->expectException(AccessDeniedHttpException::class);
        $this->read(['version_id' => $version->id]);
    }

    public function test_fresh_receipt_rejects_changes_and_revoked_model_permission(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $element = $this->element($version, 'IFCSLAB', 'Перекрытие');
        $result = $this->read(['element_query' => 'перекрытия']);
        $element->updateQuietly(['name' => 'Изменённое перекрытие']);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        $result = $this->read(['element_query' => 'перекрытия']);
        $result['bim_evidence']['models'][0]['total'] = 999;
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($this->fixture->member, 'get_bim_model_elements'));
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'design-management' => ['design-management.view']]]);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($this->fixture->member, 'get_bim_model_elements'));
        $this->expectException(AccessDeniedHttpException::class);
        $this->read([]);
    }

    public function test_more_than_five_models_requires_selection_and_never_reads_elements_or_properties(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->element($this->model('Корпус '.$i, 'корпус'.$i.'.ifc'), 'IFCSLAB', 'Перекрытие');
        }
        $result = $this->read(['model_query' => null]);
        self::assertCount(5, $result['models']);
        self::assertTrue($result['needs_clarification']);
        self::assertNull($result['models'][0]['total']);
        self::assertSame([], $result['models'][0]['elements']);
        self::assertStringNotContainsString('private-secret', json_encode($result, JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        app(GetBimModelElementsTool::class)->execute(['properties' => true], $this->fixture->member, $this->fixture->organization);
    }

    public function test_general_search_finds_file_names_and_rfi_subject_question_and_number_independently_of_output_fields(): void
    {
        $version = $this->model('Модель корпуса', 'гаражная.ifc');
        $search = app(AssistantDomainReadService::class);
        $result = $search->execute('search', ['domain' => 'design', 'entity_type' => 'design_artifact_version',
            'query' => 'гаражной', 'project_id' => $this->project->id, 'fields' => ['id', 'status']],
            $this->fixture->member, $this->fixture->organization->id);
        self::assertSame($version->id, $result['results'][0]['id']);
        self::assertArrayNotHasKey('source_original_name', $result['results'][0]['fields']);
        $this->fixture->activatePackages(['finance-contracts']);
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'], 'change-management' => ['change-management.view']]]);
        $rfi = \App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi::withoutEvents(fn () =>
            \App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi::query()->create([
                'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
                'created_by_user_id' => $this->fixture->member->id, 'rfi_number' => 'RFI-321', 'subject' => 'Монолитное перекрытие',
                'question' => 'Какая толщина плиты у гаражной?', 'answer' => 'Уточнить по расчёту', 'addressee_type' => 'designer', 'status' => 'draft']));
        foreach (['гаражной монолитного', 'RFI-321', 'толщина плиты', 'по расчёту'] as $term) {
            $result = $search->execute('search', ['domain' => 'change_management', 'entity_type' => 'change_management_rfi',
                'query' => $term, 'project_id' => $this->project->id, 'fields' => ['id', 'status']],
                $this->fixture->member, $this->fixture->organization->id);
            self::assertSame($rfi->id, $result['results'][0]['id'], $term);
            self::assertArrayNotHasKey('question', $result['results'][0]['fields']);
        }
        $read = $search->execute('read', ['domain' => 'change_management', 'entity_type' => 'change_management_rfi',
            'id' => $rfi->id, 'fields' => ['rfi_number', 'question', 'answer']], $this->fixture->member, $this->fixture->organization->id);
        self::assertSame($rfi->question, $read['results'][0]['fields']['question']);
        self::assertStringContainsString('Вопрос', $read['server_formatted_facts']);
        self::assertStringContainsString('Ответ', $read['server_formatted_facts']);
    }

    private function read(array $filters): array
    {
        return $this->reader->read($this->fixture->member, $this->fixture->organization->id,
            $filters + ['model_query' => 'гаражной', 'project_id' => $this->project->id]);
    }

    private function model(string $title, string $file, ?Project $project = null): DesignArtifactVersion
    {
        $project ??= $this->project;
        $package = DesignPackage::withoutEvents(fn () => DesignPackage::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'title' => 'Комплект', 'status' => 'draft']));
        $artifact = DesignArtifact::withoutEvents(fn () => DesignArtifact::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'package_id' => $package->id, 'title' => $title, 'artifact_type' => 'model']));

        return DesignArtifactVersion::withoutEvents(fn () => DesignArtifactVersion::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'artifact_id' => $artifact->id, 'title' => $title, 'version_number' => '1', 'source_format' => 'ifc',
            'source_file_path' => 'private-secret', 'source_original_name' => $file, 'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 1, 'is_current' => true]));
    }

    private function element(DesignArtifactVersion $version, string $category, string $name): DesignIfcModelElement
    {
        return DesignIfcModelElement::withoutEvents(fn () => DesignIfcModelElement::query()->create(['organization_id' => $version->organization_id,
            'project_id' => $version->project_id, 'version_id' => $version->id, 'express_id' => random_int(1, 1000000000),
            'category' => $category, 'name' => $name, 'properties' => ['private' => 'private-secret']]));
    }
}
