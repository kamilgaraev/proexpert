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
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
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

    public function test_category_absence_is_not_physical_absence_and_named_proxies_never_become_typed_slabs(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $this->element($version, 'IFCPLATE', 'Пластина');
        $result = $this->read(['element_query' => 'перекрытия']);
        self::assertSame(0, $result['models'][0]['total']);
        self::assertStringContainsString('не доказывает отсутствие', $result['server_formatted_answer']);
        self::assertStringContainsString('Пластины: 1', $result['server_formatted_answer']);
        $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'Перекрытие сборное');
        self::assertSame(0, $this->read(['element_query' => 'перекрытия'])['models'][0]['total']);
        self::assertSame(1, $this->read(['element_query' => 'сборное'])['models'][0]['total']);
    }

    public function test_project_name_is_not_a_model_filename_and_count_remains_a_text_match(): void
    {
        $this->project->update(['name' => 'СевГУ: учебный корпус']);
        $version = $this->model('Вентиляция', 'hvac.ifc');
        $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'Кондиционер 1');
        $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'Кондиционер 2');
        $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'Вентилятор');
        $selector = \App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelSelector::withoutProjectAlias(
            'севгу', $this->project->name, 'у нас в проекте севгу есть кондиционеры, сколько их?'
        );
        $result = $this->read(['model_query' => $selector, 'element_query' => 'кондиционер', 'project_id' => $this->project->id]);
        self::assertCount(1, $result['models']);
        self::assertSame(2, $result['models'][0]['total']);
        self::assertStringContainsString('названию', $result['server_formatted_answer']);
        self::assertStringNotContainsString('IFC-модель с таким названием не нашёл', $result['server_formatted_answer']);
        self::assertSame('verified', $this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id)['validation_status']);
    }

    public function test_structural_counts_follow_ifc_types_per_model_and_exclude_openings_and_wall_mounted_equipment(): void
    {
        $first = $this->model('Гаражная конструкции', 'structures.ifc');
        $second = $this->model('Гаражная вентиляция', 'ventilation.ifc');
        $firstIndex = $this->readyIndex($first, 7);
        $secondIndex = $this->readyIndex($second, 2);
        $rows = [
            ['IFCWALL', 'Несущая', 300],
            ['IFCWALLSTANDARDCASE', 'Перегородка', 200],
            ['IFCOPENINGELEMENT', 'Отверстие в стене', 300],
            ['IFCBUILDINGELEMENTPROXY', 'ZPX стеновой вентилятор', 300],
            ['IFCSLAB', 'Плита перекрытия', 300],
            ['IFCOPENINGELEMENT', 'Отверстие в перекрытии', 300],
            ['IFCBUILDINGELEMENTPROXY', 'Перекрытие сборное', 300],
        ];
        foreach ($rows as [$category, $name, $width]) {
            $this->element($first, $category, $name)->updateQuietly(['derivative_id' => $firstIndex->id,
                'properties' => ['_ifc' => ['schema_version' => 2], 'Pset_Common' => ['Width' => $width], 'materials' => ['Бетон']]]);
        }
        $this->element($second, 'IFCWALLSTANDARDCASE', 'Перегородка')->updateQuietly(['derivative_id' => $secondIndex->id]);
        $this->element($second, 'IFCBUILDINGELEMENTPROXY', 'Стена декоративная')->updateQuietly(['derivative_id' => $secondIndex->id]);
        $result = $this->read(['element_query' => 'стены', 'limit' => 1]);
        self::assertCount(2, $result['models']);
        self::assertSame([2, 1], array_column($result['models'], 'total'));
        self::assertSame([2, 1], array_column($result['models'], 'semantic_matching_count'));
        self::assertSame([7, 2], array_column($result['models'], 'scanned_count'));
        self::assertTrue($result['models'][0]['has_more']);
        self::assertSame(1, $this->read(['version_id' => $first->id, 'element_query' => 'IFCWALL'])['models'][0]['total']);
        self::assertSame(1, $this->read(['version_id' => $first->id, 'element_query' => 'стены', 'material_query' => 'бетон',
            'property_name' => 'Width', 'property_value' => '300'])['models'][0]['total']);
        foreach (['перекрытия', 'плиты', 'IFCSLAB'] as $alias) {
            self::assertSame(1, $this->read(['version_id' => $first->id, 'element_query' => $alias])['models'][0]['total']);
        }
        self::assertSame(1, $this->read(['version_id' => $first->id, 'element_query' => 'ZPX'])['models'][0]['total']);
        self::assertSame(2, $this->read(['version_id' => $first->id, 'element_query' => 'IFCOPENINGELEMENT'])['models'][0]['total']);
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

    public function test_estimate_links_use_exact_version_and_current_target_fields_with_scope_checks(): void
    {
        $this->allowEstimateLinks();
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $element = $this->element($version, 'IFCSLAB', 'Перекрытие');
        $work = $this->estimateItem($this->project, 'Текущее имя работы');
        $link = $this->link($version, $element, $work);
        $other = $this->model('Гаражная другая', 'другая.ifc');
        $otherElement = $this->element($other, 'IFCSLAB', 'Другое перекрытие');
        $otherElement->updateQuietly(['express_id' => $element->express_id]);
        $this->link($other, $otherElement, $this->estimateItem($this->project, 'Другая версия'));
        $ended = $this->link($version, $element, $this->estimateItem($this->project, 'Завершённая связь'));
        $ended->forceFill(['status' => 'ended'])->saveQuietly();
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        $this->link($version, $element, $this->estimateItem($hidden, 'Скрытая работа'));
        $this->link($version, $element, $this->estimateItem($foreign, 'Чужая работа'));

        self::assertSame([], $this->read(['version_id' => $version->id])['models'][0]['elements'][0]['estimate_links']);
        $result = $this->read(['version_id' => $version->id, 'include_estimate_links' => true]);
        $links = $result['models'][0]['elements'][0]['estimate_links'];
        self::assertCount(1, $links);
        self::assertSame($link->id, $links[0]['link_id']);
        self::assertSame('Текущее имя работы', $links[0]['target']['name']);
        self::assertSame($work->estimate_id, $links[0]['target']['estimate_id']);
        self::assertSame('page_limited', $result['models'][0]['estimate_link_coverage']);
        self::assertStringNotContainsString('snapshot-secret', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('private-secret', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertArrayNotHasKey('unit_price', $links[0]['target']);
        self::assertNotNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
    }

    public function test_estimate_link_receipt_and_history_refs_reject_ended_links_moved_targets_and_revoked_access(): void
    {
        $this->allowEstimateLinks();
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $element = $this->element($version, 'IFCSLAB', 'Перекрытие');
        $work = $this->estimateItem($this->project, 'Работа');
        $link = $this->link($version, $element, $work);
        $result = $this->read(['version_id' => $version->id, 'include_estimate_links' => true]);
        $link->forceFill(['status' => 'ended', 'row_version' => 2])->saveQuietly();
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
        $link->forceFill(['status' => 'active', 'row_version' => 3])->saveQuietly();
        $result = $this->read(['version_id' => $version->id, 'include_estimate_links' => true]);
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $work->estimate->updateQuietly(['project_id' => $hidden->id]);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->canRead($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
        $work->estimate->updateQuietly(['project_id' => $this->project->id]);
        $result = $this->read(['version_id' => $version->id, 'include_estimate_links' => true]);
        $permissions = $this->fixture->memberRole->module_permissions;
        $permissions['budget-estimates'] = ['budget-estimates.view'];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->canRead($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
        self::assertSame('unavailable', $this->read(['version_id' => $version->id, 'include_estimate_links' => true])['models'][0]['estimate_link_coverage']);
    }

    public function test_estimate_links_are_bounded_to_the_requested_element_page(): void
    {
        $this->allowEstimateLinks();
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $first = $this->element($version, 'IFCSLAB', 'Первое');
        $second = $this->element($version, 'IFCSLAB', 'Второе');
        for ($index = 0; $index < 21; $index++) {
            $this->link($version, $first, $this->estimateItem($this->project, 'Работа '.$index));
        }
        $this->link($version, $second, $this->estimateItem($this->project, 'Другая страница'));
        $result = $this->read(['version_id' => $version->id, 'limit' => 1, 'include_estimate_links' => true]);
        self::assertCount(20, $result['models'][0]['elements'][0]['estimate_links']);
        self::assertTrue($result['models'][0]['has_more']);
        self::assertStringContainsString('полный поиск всех связей не выполнен', $result['server_formatted_answer']);
        self::assertStringNotContainsString('Другая страница', $result['server_formatted_answer']);
    }

    public function test_equipment_properties_materials_and_full_count_use_the_index_beyond_the_page(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 25);
        for ($index = 0; $index < 24; $index++) {
            $element = $this->element($version, 'IFCUNITARYEQUIPMENT', 'Блок '.$index);
            $element->updateQuietly(['derivative_id' => $derivative->id, 'global_id' => 'equipment-'.$index,
                'properties' => ['_ifc' => ['schema_version' => 2], 'private' => 'private-secret', 'Pset_UnitaryEquipmentTypeCommon' => ['PredefinedType' => 'AIRCONDITIONINGUNIT', 'CoolingCapacity' => 3500],
                    'materials' => ['Сталь']], 'classifications' => ['HVAC']]);
        }
        $unknown = $this->element($version, 'IFCUNITARYEQUIPMENT', 'Блок без типа');
        $unknown->updateQuietly(['derivative_id' => $derivative->id, 'global_id' => 'unknown']);
        $result = $this->read(['element_query' => 'кондиционеры', 'limit' => 1]);
        self::assertSame(24, $result['models'][0]['total']);
        self::assertCount(1, $result['models'][0]['elements']);
        self::assertSame('complete', $result['models'][0]['index_coverage']['status']);
        self::assertSame(24, $this->read(['property_name' => 'CoolingCapacity', 'property_value' => '3500'])['models'][0]['total']);
        self::assertSame(24, $this->read(['material_query' => 'сталь', 'classification_query' => 'HVAC'])['models'][0]['total']);
        self::assertStringNotContainsString('private-secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
    }

    public function test_quantity_totals_use_all_matches_and_keep_unknown_units_unconfirmed(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 3, [['type' => 'IFCSIUNIT', 'unit_type' => 'VOLUMEUNIT', 'name' => 'CUBIC_METRE', 'prefix' => null]]);
        foreach ([2.5, 3.0, 4.5] as $value) {
            $this->element($version, 'IFCSLAB', 'Плита')->updateQuietly(['derivative_id' => $derivative->id,
                'properties' => ['_ifc' => ['schema_version' => 2], 'quantities' => ['Qto_SlabBaseQuantities' => ['GrossVolume' => $value]]]]);
        }
        $result = $this->read(['include_quantities' => true, 'limit' => 1]);
        self::assertSame(10.0, $result['models'][0]['quantity_totals'][0]['sum']);
        self::assertSame(3, $result['models'][0]['quantity_totals'][0]['elements']);
        self::assertSame('CUBIC_METRE', $result['models'][0]['quantity_totals'][0]['unit']);
        $derivative->updateQuietly(['metadata' => ['indexed_element_count' => 3, 'ifc_units' => []]]);
        self::assertNull($this->read(['include_quantities' => true])['models'][0]['quantity_totals'][0]['sum']);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
    }

    public function test_material_summary_reads_all_associations_beyond_page_and_changes_invalidate_receipt(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 3);
        foreach ([[], ['Сталь', 'Сталь'], ['Бетон', 'Утеплитель']] as $materials) {
            $last = $this->element($version, 'IFCWALL', 'Стена');
            $last->updateQuietly(['derivative_id' => $derivative->id,
                'properties' => ['_ifc' => ['schema_version' => 2], 'materials' => $materials]]);
        }
        $result = $this->read(['answer_kind' => 'materials', 'limit' => 1]);
        $summary = $result['models'][0]['material_summary'];
        self::assertSame(['Бетон'], $summary['names']);
        self::assertSame([], $result['models'][0]['elements'][0]['materials']);
        self::assertSame(3, $summary['total']);
        self::assertSame('full_matching_index', $summary['coverage']);
        self::assertStringContainsString('• Бетон', $result['server_formatted_answer']);
        $next = $this->read(['answer_kind' => 'materials', 'offset' => 1, 'limit' => 2]);
        self::assertSame(['Сталь', 'Утеплитель'], $next['models'][0]['material_summary']['names']);
        self::assertStringNotContainsString('Состав загруженной модели', $result['server_formatted_answer']);
        self::assertLessThan(1000, mb_strlen($result['server_formatted_answer']));
        self::assertNotNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        $last->updateQuietly(['properties' => ['_ifc' => ['schema_version' => 2], 'materials' => ['Кирпич']]]);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
    }

    public function test_material_summary_preserves_all_more_than_thirty_relations_and_never_publishes_unsafe_labels(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 1);
        $materials = array_map(static fn (int $id): string => sprintf('Материал %02d', $id), range(1, 61));
        $element = $this->element($version, 'IFCWALL', 'Слои');
        $element->updateQuietly(['derivative_id' => $derivative->id,
            'properties' => ['_ifc' => ['schema_version' => 2], 'materials' => $materials]]);
        $result = $this->read(['answer_kind' => 'materials']);
        self::assertSame(61, $result['models'][0]['material_summary']['total']);
        self::assertCount(20, $result['models'][0]['material_summary']['names']);
        self::assertTrue($result['models'][0]['material_summary']['truncated']);
        $next = $this->read(['answer_kind' => 'materials', 'offset' => 40]);
        self::assertSame('Материал 41', $next['models'][0]['material_summary']['names'][0]);
        self::assertSame(61, $next['models'][0]['material_summary']['total']);
        $element->updateQuietly(['properties' => ['_ifc' => ['schema_version' => 2],
            'materials' => ['Сталь', 'https://secret.invalid', 'user@example.com', 'password=private-secret']]]);
        $safe = $this->read(['answer_kind' => 'materials']);
        self::assertSame(['Сталь'], $safe['models'][0]['material_summary']['names']);
        self::assertSame('partial', $safe['models'][0]['material_summary']['coverage']);
        self::assertStringNotContainsString('private-secret', $safe['server_formatted_answer']);
        self::assertStringNotContainsString('example.com', $safe['server_formatted_answer']);
        self::assertStringNotContainsString('Названия материалов в подходящих элементах IFC не указаны', $safe['server_formatted_answer']);
    }

    public function test_material_summary_is_complete_above_the_php_element_scan_limit(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 100001);
        \Illuminate\Support\Facades\DB::insert("INSERT INTO design_ifc_model_elements (organization_id, project_id, version_id, derivative_id, express_id, category, name, properties, created_at, updated_at)
            SELECT ?, ?, ?, ?, item_id, 'IFCWALL', 'Стена',
                jsonb_build_object('_ifc', jsonb_build_object('schema_version', 2), 'materials',
                    CASE WHEN item_id = 100001 THEN '[\"Материал после предела сканирования\"]'::jsonb ELSE '[]'::jsonb END), NOW(), NOW()
            FROM generate_series(1, 100001) AS item_id", [$version->organization_id, $version->project_id, $version->id, $derivative->id]);
        \Illuminate\Support\Facades\DB::statement('ANALYZE design_ifc_model_elements');
        $result = $this->read(['answer_kind' => 'materials', 'limit' => 1]);
        self::assertSame(100001, $result['models'][0]['total']);
        self::assertSame(['Материал после предела сканирования'], $result['models'][0]['material_summary']['names']);
        self::assertSame('full_matching_index', $result['models'][0]['material_summary']['coverage']);
        self::assertStringContainsString('Материал после предела сканирования', $result['server_formatted_answer']);
    }

    public function test_comparison_requires_complete_same_artifact_indices_and_uses_global_ids(): void
    {
        $before = $this->model('Гаражная', 'гаражная.ifc');
        $before->updateQuietly(['is_current' => false]);
        $after = $before->replicate();
        $after->forceFill(['version_number' => '2', 'is_current' => true])->saveQuietly();
        $oldIndex = $this->readyIndex($before, 2);
        $newIndex = $this->readyIndex($after, 2);
        $this->element($before, 'IFCSLAB', 'Прежняя')->updateQuietly(['derivative_id' => $oldIndex->id, 'global_id' => 'stable']);
        $this->element($before, 'IFCSLAB', 'Удалённая')->updateQuietly(['derivative_id' => $oldIndex->id, 'global_id' => 'removed']);
        $this->element($after, 'IFCSLAB', 'Новая')->updateQuietly(['derivative_id' => $newIndex->id, 'global_id' => 'stable']);
        $this->element($after, 'IFCSLAB', 'Добавленная')->updateQuietly(['derivative_id' => $newIndex->id, 'global_id' => 'added']);
        $result = $this->read(['version_id' => $after->id, 'compare_version_id' => $before->id]);
        self::assertSame('complete', $result['comparison']['status']);
        self::assertSame(['added' => 1, 'removed' => 1, 'changed' => 1, 'unchanged' => 0], $result['comparison']['counts']);
        $newIndex->updateQuietly(['metadata' => ['indexed_element_count' => 99]]);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        $result = $this->read(['version_id' => $after->id, 'compare_version_id' => $before->id]);
        self::assertSame('unavailable', $result['comparison']['status']);
        self::assertArrayNotHasKey('counts', $result['comparison']);
    }

    public function test_comparison_never_combines_unrelated_model_artifacts(): void
    {
        $first = $this->model('Гаражная', 'гаражная.ifc');
        $second = $this->model('Гаражная другая', 'другая.ifc');
        $this->expectException(AccessDeniedHttpException::class);
        $this->read(['version_id' => $first->id, 'compare_version_id' => $second->id]);
    }

    public function test_ready_index_excludes_unassigned_legacy_elements_and_off_page_mutations_invalidate_receipt(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $legacy = $this->element($version, 'IFCSLAB', 'Устаревшее');
        $legacy->updateQuietly(['derivative_id' => null, 'properties' => []]);
        $ready = $this->readyIndex($version, 2);
        $this->element($version, 'IFCSLAB', 'Первое')->updateQuietly(['derivative_id' => $ready->id]);
        $second = $this->element($version, 'IFCSLAB', 'Второе');
        $second->updateQuietly(['derivative_id' => $ready->id]);
        $result = $this->read(['limit' => 1]);
        self::assertSame(2, $result['models'][0]['total']);
        self::assertSame('complete', $result['models'][0]['index_coverage']['status']);
        self::assertStringNotContainsString('Устаревшее', $result['server_formatted_answer']);
        $second->updateQuietly(['properties' => ['Pset_SlabCommon' => ['Width' => 42]]]);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
    }

    public function test_semantic_count_requires_schema2_metadata_and_every_index_row_marker(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 2, [['type' => 'IFCSIUNIT', 'unit_type' => 'VOLUMEUNIT', 'name' => 'CUBIC_METRE']]);
        $properties = ['Pset_Equipment' => ['PredefinedType' => 'AIRCONDITIONINGUNIT'],
            'quantities' => ['Qto_Equipment' => ['GrossVolume' => 2]]];
        $first = $this->element($version, 'IFCUNITARYEQUIPMENT', 'Кондиционер');
        $first->updateQuietly(['derivative_id' => $derivative->id, 'properties' => $properties]);
        $second = $this->element($version, 'IFCUNITARYEQUIPMENT', 'Блок');
        $second->updateQuietly(['derivative_id' => $derivative->id, 'properties' => []]);
        $filters = ['element_query' => 'кондиционеры', 'include_quantities' => true];
        $result = $this->read($filters);
        self::assertSame('complete', $result['models'][0]['index_coverage']['status']);
        self::assertSame(1, $result['models'][0]['total']);
        self::assertSame('unproven', $result['models'][0]['semantic_coverage']['status']);
        self::assertNull($result['models'][0]['semantic_matching_count']);
        self::assertNull($result['models'][0]['quantity_totals'][0]['sum']);
        $first->updateQuietly(['properties' => $properties + ['_ifc' => ['schema_version' => 2]]]);
        self::assertSame('unproven', $this->read($filters)['models'][0]['semantic_coverage']['status']);
        $second->updateQuietly(['properties' => ['_ifc' => ['schema_version' => 2]]]);
        $result = $this->read($filters);
        self::assertSame('complete', $result['models'][0]['semantic_coverage']['status']);
        self::assertSame(1, $result['models'][0]['semantic_matching_count']);
        self::assertSame(2.0, $result['models'][0]['quantity_totals'][0]['sum']);
        $derivative->updateQuietly(['metadata' => ['indexed_element_count' => 2, 'ifc_units' => $derivative->metadata['ifc_units']]]);
        $result = $this->read($filters);
        self::assertSame(1, $result['models'][0]['total']);
        self::assertNull($result['models'][0]['semantic_matching_count']);
        self::assertSame([], $result['models'][0]['units']);
    }

    public function test_bim_history_references_reject_quiet_property_and_metadata_changes_at_same_timestamp(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 1);
        $element = $this->element($version, 'IFCSLAB', 'Перекрытие');
        $element->updateQuietly(['derivative_id' => $derivative->id]);
        $result = $this->read([]);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
        DB::table('design_ifc_model_elements')->where('id', $element->id)->update(['properties' => json_encode(['Pset_SlabCommon' => ['Width' => 42]], JSON_THROW_ON_ERROR)]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
        $result = $this->read([]);
        DB::table('design_model_derivatives')->where('id', $derivative->id)->update(['metadata' => json_encode(['indexed_element_count' => 1], JSON_THROW_ON_ERROR)]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
    }

    public function test_stored_source_hash_tracks_raw_mutations_and_distinguishes_nullable_and_delimited_values(): void
    {
        $version = $this->model('Проверка источника', 'source-hash.ifc');
        $derivative = $this->readyIndex($version, 1);
        $element = $this->element($version, 'IFCSLAB', 'А|Б');
        $element->updateQuietly(['derivative_id' => $derivative->id]);
        $hash = $element->refresh()->getRawOriginal('source_content_hash');
        $originalHash = $hash;
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $hash);
        self::assertArrayNotHasKey('source_content_hash', $element->toArray());
        self::assertArrayNotHasKey('source_schema_version', $element->toArray());
        self::assertSame(2, $element->getRawOriginal('source_schema_version'));
        $updatedAt = $element->getRawOriginal('updated_at');

        foreach (['name' => 'Б', 'category' => 'IFCSLAB|А', 'global_id' => '', 'classifications' => '["Изменённая классификация"]'] as $field => $value) {
            DB::table('design_ifc_model_elements')->where('id', $element->id)->update([$field => $value]);
            $currentHash = $element->refresh()->getRawOriginal('source_content_hash');
            self::assertNotSame($hash, $currentHash, $field);
            self::assertSame($updatedAt, $element->getRawOriginal('updated_at'));
            if ($field === 'category') {
                self::assertNotSame($originalHash, $currentHash);
            }
            $hash = $currentHash;
        }

        DB::table('design_ifc_model_elements')->where('id', $element->id)->update(['global_id' => null]);
        self::assertNotSame($hash, $element->refresh()->getRawOriginal('source_content_hash'));
        foreach (['2', 1, null] as $schemaVersion) {
            DB::table('design_ifc_model_elements')->where('id', $element->id)->update(['properties' => json_encode(['_ifc' => ['schema_version' => $schemaVersion]], JSON_THROW_ON_ERROR)]);
            self::assertNull($element->refresh()->getRawOriginal('source_schema_version'));
        }
    }

    public function test_named_package_resolves_current_ifc_disciplines_with_parent_provenance_and_keeps_history_explicit(): void
    {
        $this->project->updateQuietly(['name' => 'Тестовый']);
        $versions = [];
        $packageId = null;
        foreach (['АС', 'В', 'КР', 'ТС', 'АС2'] as $index => $name) {
            $version = $this->model($name, $name.'.ifc');
            $artifact = DesignArtifact::query()->findOrFail($version->artifact_id);
            $packageId ??= (int) $artifact->package_id;
            $artifact->updateQuietly(['package_id' => $packageId, 'title' => $index === 0 ? 'Архитектура корпуса' : $name]);
            $derivative = $this->readyIndex($version, $index + 1);
            for ($elementIndex = 0; $elementIndex <= $index; $elementIndex++) {
                $this->element($version, 'IFCUNITARYEQUIPMENT', 'Кондиционер '.$elementIndex)
                    ->updateQuietly(['derivative_id' => $derivative->id, 'global_id' => 'unit-'.$index.'-'.$elementIndex]);
            }
            $versions[] = $version;
        }
        DesignPackage::query()->findOrFail($packageId)->updateQuietly(['title' => 'Севгу']);
        $versions[0]->updateQuietly(['version_number' => '2']);
        $old = $versions[0]->replicate();
        $old->forceFill(['version_number' => '1', 'is_current' => false, 'title' => 'АС прежняя'])->saveQuietly();
        $oldIndex = $this->readyIndex($old, 1);
        $this->element($old, 'IFCUNITARYEQUIPMENT', 'Кондиционер прежний')
            ->updateQuietly(['derivative_id' => $oldIndex->id, 'global_id' => 'unit-0-0']);
        $result = $this->read(['model_query' => 'Севгу', 'element_query' => 'кондиционеры', 'limit' => 1]);
        self::assertCount(5, $result['models']);
        self::assertFalse($result['needs_clarification']);
        self::assertSame([1, 2, 3, 4, 5], array_column($result['models'], 'total'));
        self::assertNotContains($old->id, array_column(array_column($result['models'], 'version'), 'id'));
        foreach ($result['models'] as $model) {
            self::assertSame('Севгу', $model['source_scope']['package']['title']);
            self::assertSame('Тестовый', $model['source_scope']['project']['name']);
            self::assertSame('current', $model['source_scope']['version_selection']);
        }
        self::assertCount(5, $this->read(['model_query' => 'sevgu'])['models']);
        self::assertCount(1, $this->read(['model_query' => 'архитектура'])['models']);
        self::assertCount(5, $this->read(['model_query' => 'Тестовый'])['models']);
        self::assertSame($old->id, $this->read(['model_query' => 'Севгу', 'version_id' => $old->id])['models'][0]['version']['id']);
        $comparison = $this->read(['version_id' => $versions[0]->id, 'compare_version_id' => $old->id]);
        self::assertSame('complete', $comparison['comparison']['status']);
        self::assertSame(1, $comparison['comparison']['counts']['changed']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
        self::assertStringContainsString('Комплект: Севгу', $result['server_formatted_answer']);
    }

    public function test_package_alias_never_admits_hidden_or_foreign_projects_and_unknown_alias_is_not_physical_absence(): void
    {
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        foreach ([[$this->project, 'АС', 'Доступный кондиционер'], [$hidden, 'В', 'Скрытый кондиционер'], [$foreign, 'КР', 'Чужой кондиционер']] as [$project, $title, $name]) {
            $version = $this->model($title, $title.'.ifc', $project);
            $artifact = DesignArtifact::query()->findOrFail($version->artifact_id);
            DesignPackage::query()->findOrFail($artifact->package_id)->updateQuietly(['title' => 'Севгу']);
            $derivative = $this->readyIndex($version, 1);
            $this->element($version, 'IFCUNITARYEQUIPMENT', $name)->updateQuietly(['derivative_id' => $derivative->id]);
        }
        $result = $this->read(['model_query' => 'Севгу', 'project_id' => null, 'element_query' => 'кондиционеры']);
        self::assertCount(1, $result['models']);
        self::assertSame(1, $result['models'][0]['total']);
        self::assertStringNotContainsString('Скрытый', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Чужой', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $unknown = $this->read(['model_query' => 'Неизвестный комплект', 'project_id' => null]);
        self::assertSame('empty', $unknown['status']);
        self::assertTrue($unknown['needs_clarification']);
        self::assertStringContainsString('не подтверждает отсутствие оборудования', $unknown['server_formatted_answer']);
    }

    public function test_parent_title_changes_invalidate_package_lookup_receipt_and_history_references(): void
    {
        $version = $this->model('АС', 'АС.ifc');
        $artifact = DesignArtifact::query()->findOrFail($version->artifact_id);
        $package = DesignPackage::query()->findOrFail($artifact->package_id);
        $package->updateQuietly(['title' => 'Севгу']);
        $this->element($version, 'IFCUNITARYEQUIPMENT', 'Кондиционер');
        $result = $this->read(['model_query' => 'Севгу']);
        self::assertCount(1, $result['models']);
        DB::table('design_packages')->where('id', $package->id)->update(['title' => 'Другой комплект']);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $result['source_refs']));
    }

    public function test_equipment_classifiers_exclude_structural_duct_and_referenced_proxy_elements(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 10);
        foreach ([['IFCWALL', 'Стена для кондиционера'], ['IFCDUCTSEGMENT', 'Воздуховод к вентилятору'],
            ['IFCFLOWFITTING', 'Отвод к кондиционеру'], ['IFCBUILDINGELEMENTPROXY', 'Подставка под кондиционер'],
            ['IFCFLOWTERMINAL', 'Кондиционер'], ['IFCFLOWMOVINGDEVICE', 'Вентилятор для кондиционера'],
            ['IFCUNITARYEQUIPMENT', 'Блок'], ['IFCBUILDINGELEMENTPROXY', 'Кронштейн кондиционера'],
            ['IFCBUILDINGELEMENTPROXY', 'Трубопровод кондиционера'], ['IFCBUILDINGELEMENTPROXY', 'Кондиционер KX02']] as [$category, $name]) {
            $element = $this->element($version, $category, $name);
            $values = ['derivative_id' => $derivative->id];
            if ($name === 'Блок') {
                $values['properties'] = ['_ifc' => ['schema_version' => 2, 'type_predefined_type' => 'AIRCONDITIONINGUNIT']];
            }
            $element->updateQuietly($values);
        }
        self::assertSame(3, $this->read(['element_query' => 'кондиционеры'])['models'][0]['total']);
        self::assertSame(1, $this->read(['equipment_kind' => 'fan'])['models'][0]['total']);
        $kinds = array_column($this->read([])['models'][0]['elements'], 'equipment_kind', 'name');
        foreach (['Стена для кондиционера', 'Воздуховод к вентилятору', 'Отвод к кондиционеру', 'Подставка под кондиционер',
            'Кронштейн кондиционера', 'Трубопровод кондиционера'] as $name) {
            self::assertSame('unknown', $kinds[$name]);
        }
        self::assertSame('fan', $kinds['Вентилятор для кондиционера']);
        self::assertSame('air_conditioner', $kinds['Блок']);
        self::assertSame('air_conditioner', $kinds['Кондиционер KX02']);
    }

    public function test_current_scoped_alias_probe_is_project_bound_and_never_reads_ifc_elements(): void
    {
        $version = $this->model('АС', 'АС.ifc');
        $artifact = DesignArtifact::query()->findOrFail($version->artifact_id);
        DesignPackage::query()->findOrFail($artifact->package_id)->updateQuietly(['title' => 'Севгу']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            self::assertTrue($this->reader->hasCurrentScopedAlias($this->fixture->member, $this->fixture->organization->id, $this->project->id, 'Севгу'));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        foreach ($queries as $query) {
            self::assertStringNotContainsString('from "design_ifc_model_elements"', $query['query']);
        }
        self::assertFalse($this->reader->hasCurrentScopedAlias($this->fixture->member, $this->fixture->organization->id, $this->project->id, 'Другой комплект'));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        self::assertFalse($this->reader->hasCurrentScopedAlias($this->fixture->member, $this->fixture->organization->id, $hidden->id, 'Севгу'));
        $version->updateQuietly(['is_current' => false]);
        self::assertFalse($this->reader->hasCurrentScopedAlias($this->fixture->member, $this->fixture->organization->id, $this->project->id, 'Севгу'));
    }

    public function test_engineering_hvac_label_aliases_match_real_units_and_never_rooms_accessories_or_materials_alone(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $rows = [
            ['IFCBUILDINGELEMENTPROXY', 'TYPE-OUTDOOR', 'GP_Наименование', 'Наружный блок мультизональной системы Qх=68.0Вт, Qт=75.0кВт,380-415/3/50', 'Оборудование'],
            ['IFCBUILDINGELEMENTPROXY', 'TYPE-INDOOR', 'GP_Наименование', "Сплит-система Qх=5100Вт, Qт=5200Вт, U=220/1/50, N=1,6кВт в\r\nкомплекте пульт дистанционного управления", 'Оборудование'],
            ['IFCBUILDINGELEMENTPROXY', 'TYPE-VRF', 'ADSK_Наименование', 'Внутренний блок VRF', 'Оборудование'],
            ['IFCFLOWTERMINAL', 'TYPE-VRV', 'Наименование', 'Наружный блок VRV', 'Оборудование'],
            ['IFCBUILDINGELEMENTPROXY', 'ACCESSORY-BRACKET', 'GP_Наименование', 'Кронштейн наружного блока мультизональной системы', 'Оборудование'],
            ['IFCBUILDINGELEMENTPROXY', 'ACCESSORY-GRILLE', 'GP_Наименование', 'Решетка внутреннего блока VRF', 'Оборудование'],
            ['IFCSPACE', 'ROOM-TYPED', 'GP_Наименование', 'Наружный блок мультизональной системы', 'Помещения'],
            ['IFCBUILDINGELEMENTPROXY', 'ROOM-PROXY', 'GP_Наименование', 'Помещение с кондиционером', 'Помещения'],
            ['IFCBUILDINGELEMENTPROXY', 'MATERIALS-ONLY', 'GP_Наименование', 'Объект', 'Оборудование'],
        ];
        $derivative = $this->readyIndex($version, count($rows));
        foreach ($rows as [$category, $name, $alias, $label, $revitCategory]) {
            $this->element($version, $category, $name)->updateQuietly(['derivative_id' => $derivative->id,
                'properties' => ['_ifc' => ['schema_version' => 2, 'type_predefined_type' => 'NOTDEFINED'],
                    'Идентификация' => [$alias => $label, 'CoolingCapacity' => 3500],
                    'Прочее' => ['Категория' => $revitCategory], 'materials' => ['Корпус кондиционера', 'Решетка кондиционера'],
                    'private' => 'private-secret']]);
        }
        $result = $this->read(['equipment_kind' => 'air_conditioner', 'limit' => 1, 'include_properties' => true]);
        self::assertSame(4, $result['models'][0]['total']);
        self::assertSame(4, $result['models'][0]['semantic_matching_count']);
        self::assertCount(1, $result['models'][0]['elements']);
        self::assertArrayHasKey('Идентификация.GP_Наименование', $result['models'][0]['elements'][0]['properties']);
        self::assertStringNotContainsString('private-secret', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $kinds = array_column($this->read([])['models'][0]['elements'], 'equipment_kind', 'name');
        foreach (['TYPE-OUTDOOR', 'TYPE-INDOOR', 'TYPE-VRF', 'TYPE-VRV'] as $name) {
            self::assertSame('air_conditioner', $kinds[$name]);
        }
        foreach (['ACCESSORY-BRACKET', 'ACCESSORY-GRILLE', 'ROOM-TYPED', 'ROOM-PROXY', 'MATERIALS-ONLY'] as $name) {
            self::assertSame('unknown', $kinds[$name]);
        }
    }

    public function test_raw_room_and_identity_classification_match_sql_after_the_display_property_limit(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 2);
        $base = [];
        for ($index = 0; $index < 110; $index++) {
            $base['Pset_'.$index] = ['Width' => $index];
        }
        $base['_ifc'] = ['schema_version' => 2];
        $room = $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'Кондиционер');
        $room->updateQuietly(['derivative_id' => $derivative->id, 'properties' => $base + ['Прочее' => ['Категория' => 'Помещения']]]);
        $device = $this->element($version, 'IFCBUILDINGELEMENTPROXY', 'TYPE-OUTDOOR');
        $device->updateQuietly(['derivative_id' => $derivative->id,
            'properties' => $base + ['Идентификация' => ['GP_Наименование' => 'Наружный блок мультизональной системы']]]);
        $page = $this->read(['include_properties' => true]);
        self::assertSame(2, $page['models'][0]['total']);
        $elements = array_column($page['models'][0]['elements'], null, 'name');
        self::assertSame('unknown', $elements['Кондиционер']['equipment_kind']);
        self::assertSame('air_conditioner', $elements['TYPE-OUTDOOR']['equipment_kind']);
        foreach ($elements as $element) {
            self::assertCount(100, $element['properties']);
        }
        $matches = $this->read(['equipment_kind' => 'air_conditioner', 'include_properties' => true]);
        self::assertSame(1, $matches['models'][0]['total']);
        self::assertSame(1, $matches['models'][0]['semantic_matching_count']);
        self::assertSame('TYPE-OUTDOOR', $matches['models'][0]['elements'][0]['name']);
        self::assertSame('air_conditioner', $matches['models'][0]['elements'][0]['equipment_kind']);
    }

    public function test_bounded_equipment_queries_preserve_full_proof_unknown_rows_and_off_page_freshness(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $derivative = $this->readyIndex($version, 6);
        $rows = [
            ['IFCBUILDINGELEMENTPROXY', 'TYPE-OUTDOOR', ['Идентификация' => ['GP_Наименование' => 'Наружный блок мультизональной системы']]],
            ['IFCUNITARYEQUIPMENT', 'Типовой блок', ['Pset_Device' => ['PredefinedType' => 'AIRCONDITIONINGUNIT']]],
            ['IFCWALL', 'Стена для кондиционера', []],
            ['IFCBUILDINGELEMENTPROXY', 'Кронштейн кондиционера', []],
            ['IFCPUMP', 'Насос', []],
            ['IFCBUILDINGELEMENTPROXY', 'Кондиционер последний', []],
        ];
        foreach ($rows as [$category, $name, $properties]) {
            $element = $this->element($version, $category, $name);
            $element->updateQuietly(['derivative_id' => $derivative->id,
                'properties' => ['_ifc' => ['schema_version' => 2]] + $properties]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $this->read(['version_id' => $version->id, 'equipment_kind' => 'air_conditioner', 'limit' => 1]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $model = $result['models'][0];
        self::assertSame(6, $model['scanned_count']);
        self::assertSame(6, $model['schema2_row_count']);
        self::assertSame(3, $model['total']);
        self::assertSame(3, $model['semantic_matching_count']);
        self::assertSame('complete', $model['index_coverage']['status']);
        self::assertTrue($model['has_more']);
        self::assertCount(1, $model['elements']);
        $proofs = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'AS indexed_count')));
        self::assertCount(1, $proofs);
        self::assertStringContainsString('COUNT(*) FILTER', $proofs[0]['query']);
        self::assertStringContainsString('source_content_hash', $proofs[0]['query']);
        self::assertStringContainsString('"design_ifc_model_elements"."id" in (', $proofs[0]['query']);
        self::assertStringContainsString('"design_ifc_model_elements"."project_id" = ? and "design_ifc_model_elements"."version_id" = ? and "design_ifc_model_elements"."derivative_id" = ?', $proofs[0]['query']);
        self::assertSame([(int) $version->project_id, (int) $version->id, (int) $derivative->id], array_map('intval', array_slice($proofs[0]['bindings'], -3)));
        $unknown = $this->read(['version_id' => $version->id, 'equipment_kind' => 'unknown']);
        self::assertSame(2, $unknown['models'][0]['total']);
        self::assertSame(6, $unknown['models'][0]['scanned_count']);
        self::assertSame(1, $this->read(['version_id' => $version->id, 'equipment_kind' => 'pump'])['models'][0]['total']);
        $element->updateQuietly(['properties' => ['_ifc' => ['schema_version' => 2], 'Pset_Device' => ['Width' => 42]]]);
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
    }

    public function test_stored_equipment_kind_matches_private_bounded_projection_and_all_raw_mutations(): void
    {
        $version = $this->model('Гаражная', 'гаражная.ifc');
        $wide = [];
        for ($index = 0; $index < 110; $index++) {
            $wide['Pset_'.$index] = ['Width' => $index];
        }
        $rows = [
            ['IFCPUMP', 'Кондиционер', ['Прочее' => ['Категория' => 'Помещения']], [], 'pump'],
            [null, 'Кондиционер', [], [], 'unknown'],
            ['IFCWALL', 'Кондиционер', [], [], 'unknown'],
            ['IFCBUILDINGELEMENTPROXY', 'Кондиционер KX02', [], [], 'air_conditioner'],
            ['IFCBUILDINGELEMENTPROXY', 'Кронштейн кондиционера', [], [], 'unknown'],
            ['IFCBUILDINGELEMENTPROXY', 'TYPE-OUTDOOR', $wide + ['Идентификация' => ['GP_Наименование' => 'Наружный блок мультизональной системы']], [], 'air_conditioner'],
            ['IFCBUILDINGELEMENTPROXY', 'Кондиционер', $wide + ['Прочее' => ['Категория' => 'Помещения']], [], 'unknown'],
            ['IFCBUILDINGELEMENTPROXY', 'Блок', ['Идентификация' => ['GP_Наименование' => 'Кондиционер https://private.example']], [], 'unknown'],
            ['IFCBUILDINGELEMENTPROXY', 'Блок', [], ['Кондиционер'], 'air_conditioner'],
            ['IFCUNITARYEQUIPMENT', 'Блок', ['_ifc' => ['type_predefined_type' => 'AIRCONDITIONINGUNIT']], [], 'air_conditioner'],
            ['IFCUNITARYEQUIPMENT', 'Блок', ['Pset_Device' => ['PredefinedType' => ' AIRCONDITIONINGUNIT ']], [], 'air_conditioner'],
        ];
        $derivative = $this->readyIndex($version, count($rows));
        $projection = new \App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexProjection;
        foreach ($rows as [$category, $name, $properties, $classifications, $expected]) {
            $properties['_ifc'] = ($properties['_ifc'] ?? []) + ['schema_version' => 2];
            $element = $this->element($version, $category ?? 'IFCBUILDINGELEMENTPROXY', $name);
            $element->updateQuietly(['category' => $category, 'derivative_id' => $derivative->id,
                'properties' => $properties, 'classifications' => $classifications]);
            $element->refresh();
            self::assertSame($expected, $element->getRawOriginal('source_equipment_kind'));
            self::assertSame($expected, $projection->element($element->attributesToArray())['equipment_kind']);
            self::assertArrayNotHasKey('source_equipment_kind', $element->toArray());
        }
        $result = $this->read(['version_id' => $version->id, 'equipment_kind' => 'air_conditioner', 'limit' => 1]);
        self::assertSame(5, $result['models'][0]['total']);
        self::assertSame(count($rows), $result['models'][0]['scanned_count']);
        $updatedAt = $element->getRawOriginal('updated_at');
        $hash = $element->getRawOriginal('source_content_hash');
        $mutations = [
            [['name' => 'Кронштейн кондиционера', 'properties' => ['_ifc' => ['schema_version' => 2]]], 'unknown'],
            [['properties' => ['_ifc' => ['schema_version' => 2], 'Идентификация' => ['GP_Наименование' => 'Внутренний блок VRF системы']]], 'air_conditioner'],
            [['properties' => ['_ifc' => ['schema_version' => 2], 'Прочее' => ['Категория' => 'Помещения']]], 'unknown'],
            [['category' => 'IFCPUMP'], 'pump'],
            [['category' => 'IFCBUILDINGELEMENTPROXY', 'properties' => ['_ifc' => ['schema_version' => 2]], 'classifications' => ['Кондиционер']], 'air_conditioner'],
            [['classifications' => ['Кондиционер private@example.test']], 'unknown'],
        ];
        foreach ($mutations as [$changes, $expected]) {
            foreach (['properties', 'classifications'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $changes[$field] = json_encode($changes[$field], JSON_THROW_ON_ERROR);
                }
            }
            DB::table('design_ifc_model_elements')->where('id', $element->id)->update($changes);
            $element->refresh();
            self::assertSame($expected, $element->getRawOriginal('source_equipment_kind'));
            self::assertSame($expected, $projection->element($element->attributesToArray())['equipment_kind']);
            self::assertNotSame($hash, $element->getRawOriginal('source_content_hash'));
            self::assertSame($updatedAt, $element->getRawOriginal('updated_at'));
            $hash = $element->getRawOriginal('source_content_hash');
        }
        self::assertNull($this->reader->verifiedAnswer($result, $this->fixture->member, $this->fixture->organization->id));
    }

    private function readyIndex(DesignArtifactVersion $version, int $count, array $units = []): DesignModelDerivative
    {
        $units = array_map(static fn (array $unit): array => $unit + ['source' => 'project_units_in_context'], $units);
        return DesignModelDerivative::withoutEvents(fn () => DesignModelDerivative::query()->create([
            'organization_id' => $version->organization_id, 'project_id' => $version->project_id, 'version_id' => $version->id,
            'status' => 'ready', 'viewer_provider' => 'thatopen', 'derivative_format' => 'thatopen_frag',
            'metadata' => ['ifc_index_schema_version' => 2, 'indexed_element_count' => $count, 'ifc_units' => $units]]));
    }

    private function allowEstimateLinks(): void
    {
        $this->fixture->activatePackages(['finance-contracts']);
        $permissions = $this->fixture->memberRole->module_permissions;
        $permissions['budget-estimates'] = ['budget-estimates.view', 'budget-estimates.finance.view'];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
    }

    private function estimateItem(Project $project, string $name): EstimateItem
    {
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'number' => 'EST-'.$name, 'name' => $name, 'type' => 'local', 'status' => 'draft',
            'estimate_date' => '2026-10-11', 'total_amount' => 123, 'total_amount_with_vat' => 123]));
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $project->organization_id, 'short_name' => 'link-unit'],
            ['name' => 'Единица', 'type' => 'work', 'is_default' => false, 'is_system' => false]);

        return EstimateItem::withoutEvents(fn () => EstimateItem::query()->create(['estimate_id' => $estimate->id,
            'position_number' => '1', 'item_type' => 'work', 'name' => $name, 'measurement_unit_id' => $unit->id,
            'quantity' => 2, 'unit_price' => 50, 'direct_costs' => 123, 'total_amount' => 123, 'is_manual' => true]));
    }

    private function link(DesignArtifactVersion $version, DesignIfcModelElement $element, EstimateItem $work): DesignSourceLink
    {
        return DesignSourceLink::withoutEvents(fn () => DesignSourceLink::query()->create(['organization_id' => $version->organization_id,
            'project_id' => $version->project_id, 'source_version_id' => $version->id, 'source_element_id' => $element->express_id,
            'target_type' => 'estimate_item', 'target_id' => $work->id, 'target_snapshot' => ['label' => 'snapshot-secret', 'unit_price' => 999],
            'created_by' => $this->fixture->owner->id]));
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
            'category' => $category, 'name' => $name, 'properties' => ['_ifc' => ['schema_version' => 2], 'private' => 'private-secret']]));
    }
}
