<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantAgentDecision;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAgentReportScopeTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private ReportScopeCallbackService $service;
    private AssistantAgentPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = AssistantRealAuthorizationFixture::create();
        $this->fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'],
        ]]);
        $this->service = new ReportScopeCallbackService(app(AssistantAgentExecutor::class));
        $this->planner = new AssistantAgentPlanner(new AssistantCapabilityCatalog,
            new AssistantPeriodResolver(CarbonImmutable::parse('2026-10-11', 'Europe/Moscow')));
    }

    public function test_service_callback_finds_only_current_authorized_named_project_and_overrides_ui_id(): void
    {
        $visible = $this->project('СевГУ', true);
        $ui = $this->project('Другой проект', true);
        $this->project('СевГУ', false);
        Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->foreignOrganization->id, 'name' => 'СевГУ',
        ]));
        self::assertSame('search_projects', app(AIToolRegistry::class)->getTool('search_projects')?->getName());
        $decision = $this->decision('Сформируй PDF отчет по графику работ проекта СевГУ за ноябрь 2026', $ui->id);
        self::assertSame('execute_tool', $decision->type);
        self::assertSame($visible->id, $decision->toolArguments['project_id']);
        self::assertSame('2026-11-01', $decision->toolArguments['date_from']);
        self::assertSame('2026-11-30', $decision->toolArguments['date_to']);
    }

    public function test_empty_and_ambiguous_search_never_fall_back_to_ui_or_organization(): void
    {
        $ui = $this->project('Другой проект', true);
        $this->project('СевГУ корпус А', true);
        $this->project('СевГУ корпус Б', true);
        foreach (['СевГУ', 'Несуществующий объект'] as $name) {
            $decision = $this->decision('Сформируй отчет по графику работ проекта '.$name.' за ноябрь 2026', $ui->id);
            self::assertSame('ask_clarification', $decision->type);
            self::assertSame(['project_id'], $decision->state?->missingRequiredSlotNames());
            self::assertSame([], $decision->toolArguments);
        }
    }

    public function test_hidden_foreign_and_revoked_context_ids_do_not_become_organization_reports(): void
    {
        $visible = $this->project('Доступный', true);
        $hidden = $this->project('Скрытый', false);
        $foreign = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->foreignOrganization->id, 'name' => 'Чужой',
        ]));
        self::assertSame($visible->id, $this->decision('Сформируй отчет по графику работ за ноябрь 2026', $visible->id)->toolArguments['project_id']);
        $this->fixture->member->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        foreach ([$visible->id, $hidden->id, $foreign->id] as $id) {
            $decision = $this->decision('Сформируй отчет по графику работ за ноябрь 2026', $id);
            self::assertSame('ask_clarification', $decision->type);
            self::assertNull($decision->state?->slotValue('project_id'));
            self::assertSame([], $decision->toolArguments);
        }
    }

    public function test_permission_revocation_blocks_named_scope_and_explicit_organization_scope_ignores_ui(): void
    {
        $project = $this->project('СевГУ', true);
        self::assertSame('execute_tool', $this->decision('Сформируй отчет по графику работ проекта СевГУ за ноябрь 2026', $project->id)->type);
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate']]]);
        $denied = $this->decision('Сформируй отчет по графику работ проекта СевГУ за ноябрь 2026', $project->id);
        self::assertSame('ask_clarification', $denied->type);
        self::assertSame([], $denied->toolArguments);
        $organization = $this->decision('Сформируй отчет по графику работ за ноябрь 2026 по всей организации', $project->id);
        self::assertSame('execute_tool', $organization->type);
        self::assertArrayNotHasKey('project_id', $organization->toolArguments);
    }

    private function project(string $name, bool $assigned): Project
    {
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'name' => $name,
        ]));
        if ($assigned) {
            $this->fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        }
        return $project;
    }

    private function decision(string $message, int $uiProjectId): AssistantAgentDecision
    {
        return $this->planner->decide($message, ['entity_refs' => [
            ['type' => 'project', 'id' => $uiProjectId, 'label' => 'Недостоверная подпись'],
        ]], null, fn (string $type, ?string $name, ?int $id): ?array => $this->service->scope(
            $type, $name, $id, $this->fixture->member, $this->fixture->organization->id));
    }
}

final class ReportScopeCallbackService extends AIAssistantService
{
    public function __construct(AssistantAgentExecutor $executor)
    {
        $this->agentExecutor = $executor;
    }

    public function scope(string $type, ?string $name, ?int $id, User $actor, int $organizationId): ?array
    {
        return $this->resolveAgentReportEntityScope($type, $name, $id, $actor, $organizationId, []);
    }
}
