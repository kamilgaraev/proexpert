<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectJournalReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectSafetyReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectChangeReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDesignReader;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOperationalReadinessTest extends TestCase
{
    public function test_journal_authors_follow_native_entry_visibility_without_user_directory_access(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $actor = Model::withoutEvents(fn () => $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'construction-journal' => ['construction-journal.view'], 'budget-estimates' => ['budget-estimates.view']]));
        $org = (int) $fixture->organization->id;
        Model::withoutEvents(function () use ($actor, $project, $org): void {
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
            \App\Domain\Authorization\Models\UserRoleAssignment::assignRole(user: $actor, roleSlug: 'project_manager',
                context: \App\Domain\Authorization\Models\AuthorizationContext::getProjectContext((int) $project->id, $org));
        });
        $journal = Model::withoutEvents(fn () => ConstructionJournal::query()->create(['organization_id' => $org, 'performing_organization_id' => $org,
            'project_id' => $project->id, 'name' => 'Авторство', 'journal_number' => 'AUTHOR-1', 'start_date' => '2026-10-01', 'status' => 'active', 'created_by_user_id' => $actor->id]));
        Model::withoutEvents(fn () => ConstructionJournalEntry::query()->create(['journal_id' => $journal->id, 'entry_date' => '2026-10-01',
            'entry_number' => 1, 'work_description' => 'Работа', 'status' => 'draft', 'created_by_user_id' => $fixture->owner->id]));
        $access = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class);
        self::assertFalse($access->canReadDomain($actor, $org, 'people'));
        $reader = app(AssistantProjectJournalReader::class);
        $result = $reader->read($actor, $org, ['project_id' => (int) $project->id]);
        self::assertSame([(int) $fixture->owner->id => $fixture->owner->name], $result['data']['creator_names']);
        self::assertStringNotContainsString($fixture->owner->email, json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertTrue($reader->matchesReference($actor, $org, $result['source_refs'][0]));
        $fixture->owner->updateQuietly(['name' => 'Имя изменено']);
        self::assertFalse($reader->matchesReference($actor, $org, $result['source_refs'][0]));
    }

    public function test_operational_summaries_use_current_native_states_complete_counts_and_project_isolation(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        Model::withoutEvents(fn () => \App\Domain\Authorization\Models\UserRoleAssignment::assignRole(
            user: $fixture->owner, roleSlug: 'project_manager',
            context: \App\Domain\Authorization\Models\AuthorizationContext::getProjectContext((int) $project->id, (int) $fixture->organization->id),
        ));
        $args = ['project_id' => $project->id]; $actor = $fixture->owner; $org = $fixture->organization->id;
        $guard = app(AssistantSourceReferenceGuard::class);
        $journal = Model::withoutEvents(fn () => ConstructionJournal::query()->create(['organization_id' => $org, 'performing_organization_id' => $org,
            'project_id' => $project->id, 'name' => 'Работы', 'journal_number' => 'OPER-1', 'start_date' => '2026-10-01', 'status' => 'active', 'created_by_user_id' => $actor->id]));
        Model::withoutEvents(function () use ($journal, $actor): void {
            foreach (['draft', 'draft', 'draft', 'draft', 'approved', 'submitted', 'rejected'] as $i => $status) {
                ConstructionJournalEntry::query()->create(['journal_id' => $journal->id, 'entry_date' => '2026-10-01', 'entry_number' => $i + 1,
                    'work_description' => 'Работа '.$i, 'status' => $status, 'created_by_user_id' => $actor->id]);
            }
        });
        $journalReader = app(AssistantProjectJournalReader::class);
        $journals = $journalReader->read($actor, $org, $args);
        self::assertSame(7, $journals['data']['entry_total']); self::assertSame(4, $journals['data']['entry_counts']['draft']);
        self::assertCount(3, $journals['data']['examples']['draft']);
        self::assertStringContainsString('Факт бумажной или электронной подписи', $journals['server_formatted_answer']);
        self::assertTrue($guard->fresh($actor, $org, $journals['source_refs']));
        $entry = ConstructionJournalEntry::query()->where('status', 'draft')->firstOrFail(); $entry->updateQuietly(['status' => 'approved']);
        self::assertFalse($journalReader->matchesReference($actor, $org, $journals['source_refs'][0]));
        $safety = app(AssistantProjectSafetyReader::class); $empty = $safety->read($actor, $org, $args);
        self::assertSame(0, $empty['data']['sections']['incident']['total']); self::assertSame(0, $empty['data']['sections']['violation']['total']);
        $incident = Model::withoutEvents(fn () => SafetyIncident::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'reported_by_user_id' => $actor->id, 'incident_number' => 'INC-OPER', 'title' => 'Происшествие', 'incident_type' => 'near_miss', 'severity' => 'critical', 'status' => 'reported', 'occurred_at' => now()]));
        $violation = Model::withoutEvents(fn () => SafetyViolation::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'created_by_user_id' => $actor->id, 'assigned_to_user_id' => $actor->id, 'violation_number' => 'V-OPER', 'title' => 'Нарушение', 'severity' => 'high', 'status' => 'open', 'due_date' => today()->subDay()]));
        Model::withoutEvents(fn () => SafetyViolation::query()->create(['organization_id' => $org, 'project_id' => $other->id,
            'created_by_user_id' => $actor->id, 'violation_number' => 'OTHER', 'title' => 'Чужой проект', 'severity' => 'critical', 'status' => 'open']));
        $safe = $safety->read($actor, $org, $args);
        self::assertSame(1, $safe['data']['sections']['incident']['total']); self::assertSame(1, $safe['data']['sections']['violation']['overdue']);
        self::assertStringNotContainsString('Чужой проект', json_encode($safe, JSON_UNESCAPED_UNICODE));
        self::assertTrue($guard->fresh($actor, $org, $safe['source_refs']));
        $violation->updateQuietly(['status' => 'resolved']); self::assertFalse($safety->matchesReference($actor, $org, $safe['source_refs'][0]));
        $changes = app(AssistantProjectChangeReader::class); $changeEmpty = $changes->read($actor, $org, $args);
        self::assertSame(0, $changeEmpty['data']['unanswered']); self::assertSame(0, $changeEmpty['data']['changes']['pending']);
        $rfi = Model::withoutEvents(fn () => ChangeManagementRfi::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'created_by_user_id' => $actor->id, 'rfi_number' => 'RFI-OPER', 'subject' => 'Уточнить узел', 'question' => 'Нужен ответ', 'addressee_type' => 'designer', 'status' => 'sent', 'response_due_date' => today()->subDay()]));
        $change = Model::withoutEvents(fn () => ChangeRequest::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'created_by_user_id' => $actor->id, 'change_number' => 'CH-OPER', 'title' => 'Изменение', 'reason' => 'Уточнение', 'description' => 'Работы', 'initiator_type' => 'contractor', 'status' => 'internal_review', 'reporting_currency' => 'RUB']));
        Model::withoutEvents(fn () => ChangeImpact::query()->create(['organization_id' => $org, 'change_request_id' => $change->id, 'cost_delta' => '100.00', 'schedule_delta_days' => 3]));
        $changed = $changes->read($actor, $org, $args);
        self::assertSame(1, $changed['data']['unanswered']); self::assertSame(1, $changed['data']['overdue_rfis']);
        self::assertSame(1, $changed['data']['changes']['pending']); self::assertSame(1, $changed['data']['changes']['positive_schedule_impacts']);
        self::assertTrue($guard->fresh($actor, $org, $changed['source_refs']));
        $rfi->updateQuietly(['status' => 'answered']); self::assertFalse($changes->matchesReference($actor, $org, $changed['source_refs'][0]));
        $package = Model::withoutEvents(fn () => DesignPackage::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'title' => 'Пустой комплект', 'project_stage' => 'pd', 'status' => 'draft', 'metadata' => []]));
        Model::withoutEvents(fn () => DesignPackageSection::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'package_id' => $package->id,
            'code' => 'REQ', 'title' => 'Обязательный раздел', 'project_stage' => 'pd', 'required' => true]));
        $design = app(AssistantProjectDesignReader::class); $ready = $design->read($actor, $org, $args);
        self::assertSame(1, $ready['data']['not_issued']); self::assertSame(1, $ready['data']['examples'][0]['required_empty_sections']);
        self::assertNull($ready['data']['examples'][0]['check']);
        self::assertStringContainsString('Текущая готовность к следующему этапу не подтверждена', $ready['server_formatted_answer']);
        self::assertTrue($guard->fresh($actor, $org, $ready['source_refs']));
        $revision = Model::withoutEvents(fn () => \App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision::query()->create([
            'organization_id' => $org, 'project_id' => $project->id, 'package_id' => $package->id, 'revision_number' => 1, 'state_version' => 1,
            'status' => 'approved', 'composition' => ['oversized' => str_repeat('x', 1048577)], 'fingerprint' => str_repeat('a', 64), 'created_by' => $actor->id,
        ]));
        $package->updateQuietly(['composition_revision_id' => $revision->id]);
        $oversized = $design->read($actor, $org, $args);
        self::assertFalse($oversized['data']['examples'][0]['readiness_available']);
        $package->updateQuietly(['composition_revision_id' => null, 'metadata' => ['oversized' => str_repeat('x', 262145)]]);
        self::assertFalse($design->read($actor, $org, $args)['data']['examples'][0]['readiness_available']);
        $package->updateQuietly(['status' => 'issued']); self::assertFalse($design->matchesReference($actor, $org, $ready['source_refs'][0]));
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        foreach ([[$journalReader, $journals], [$safety, $safe], [$changes, $changed], [$design, $ready]] as [$reader, $proof]) {
            self::assertFalse($reader->matchesReference($actor, $org, $proof['source_refs'][0]));
        }
    }
}
