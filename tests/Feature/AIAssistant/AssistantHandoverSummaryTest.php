<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectHandoverReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantHandoverSummaryTest extends TestCase
{
    public function test_handover_summary_uses_native_blockers_full_counts_current_acl_and_bounded_examples(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $reader = app(AssistantProjectHandoverReader::class);
        self::assertSame(0, $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id])['data']['total']);
        $scope = Model::withoutEvents(fn () => AcceptanceScope::query()->create(['organization_id' => $fixture->organization->id,
            'project_id' => $project->id, 'title' => 'Зона на проверке', 'status' => 'in_progress', 'created_by' => $fixture->owner->id]));
        $checklist = Model::withoutEvents(fn () => AcceptanceChecklist::query()->create(['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id, 'title' => 'Готовность', 'status' => 'findings_open']));
        $item = Model::withoutEvents(fn () => AcceptanceChecklistItem::query()->create(['acceptance_checklist_id' => $checklist->id,
            'title' => 'Закрыть замечания', 'status' => 'rejected', 'is_required' => true]));
        Model::withoutEvents(function () use ($fixture, $project, $other): void {
            foreach (range(1, 21) as $i) {
                AcceptanceScope::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                    'title' => 'Принятая зона '.$i, 'status' => 'accepted', 'created_by' => $fixture->owner->id]);
            }
            AcceptanceScope::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $other->id,
                'title' => 'Другой проект', 'status' => 'handed_over', 'created_by' => $fixture->owner->id]);
            AcceptanceScope::query()->create(['organization_id' => $fixture->foreignOrganization->id, 'project_id' => $project->id,
                'title' => 'Чужая организация', 'status' => 'handed_over', 'created_by' => $fixture->owner->id]);
        });
        $result = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(22, $result['data']['total']);
        self::assertCount(20, $result['data']['examples']);
        self::assertSame(21, $result['data']['status_counts']['accepted']);
        self::assertSame(0, $result['data']['status_counts']['handed_over']);
        self::assertSame(['required_checklist_incomplete' => 1], $result['data']['examples'][0]['acceptance_blockers']);
        self::assertSame(1, $result['data']['examples'][0]['handover_blockers']['required_documents_block_handover']);
        self::assertStringContainsString('обязательные пункты чек-листа', $result['server_formatted_answer']);
        self::assertStringContainsString('пакет передачи отсутствует', $result['server_formatted_answer']);
        self::assertStringContainsString('Следующий шаг', $result['server_formatted_answer']);
        self::assertStringNotContainsString('Чужая организация', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('Другой проект', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertArrayNotHasKey('evidence_snapshot', $result['data']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($fixture->owner, $fixture->organization->id, $result['source_refs']));
        self::assertNotNull($reader->verifiedAnswer($result, $fixture->owner, $fixture->organization->id));
        $item->updateQuietly(['status' => 'accepted']);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $result['source_refs'][0]));
        $current = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertTrue($current['data']['examples'][0]['acceptance_ready']);
        self::assertFalse($current['data']['examples'][0]['handover_ready']);
        $set = Model::withoutEvents(fn () => \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'created_by' => $fixture->owner->id,
            'set_number' => 'SUMMARY-1', 'title' => 'Документы зоны', 'status' => 'draft']));
        $package = Model::withoutEvents(fn () => \App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $fixture->owner->id, 'executive_document_set_id' => $set->id, 'title' => 'Передача', 'status' => 'draft']));
        $requirement = Model::withoutEvents(fn () => \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'document_set_id' => $set->id,
            'stage' => 'document_review', 'requirement_key' => 'summary', 'title' => 'Требование', 'profile_type' => 'hidden_work_act',
            'applicability' => 'required', 'revision' => 1, 'source' => 'test', 'source_revision' => '1', 'rule_snapshot' => [],
            'coverage_scope' => [], 'evidence' => array_map(static fn (int $id): array => ['version_id' => $id], range(1, 501))]));
        $nested = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertFalse($nested['data']['examples'][0]['readiness_available']);
        $requirement->updateQuietly(['evidence' => [], 'rule_snapshot' => ['oversized' => str_repeat('x', 262145)]]);
        self::assertFalse($reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id])['data']['examples'][0]['readiness_available']);
        $package->deleteQuietly();
        $item->updateQuietly(['status' => 'rejected']);
        Model::withoutEvents(function () use ($scope): void {
            foreach (range(1, 501) as $i) { AcceptanceChecklist::query()->create(['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
                'acceptance_scope_id' => $scope->id, 'title' => 'Большой список '.$i, 'status' => 'planned']); }
        });
        $capped = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertFalse($capped['data']['examples'][0]['readiness_available']);
        self::assertArrayNotHasKey('acceptance_ready', $capped['data']['examples'][0]);
        self::assertStringContainsString('Готовность этой зоны не определена', $capped['server_formatted_answer']);
        self::assertFalse($reader->matchesReference($fixture->foreignOwner, $fixture->organization->id, $capped['source_refs'][0]));
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $capped['source_refs'][0]));
    }
}
