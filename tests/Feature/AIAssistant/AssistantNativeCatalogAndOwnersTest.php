<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectReportCatalogReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectSafetyReader;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantNativeCatalogAndOwnersTest extends TestCase
{
    public function test_public_catalog_reader_uses_standalone_readonly_fence_with_active_assistant_guard(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $defaultName = \Illuminate\Support\Facades\DB::getDefaultConnection();
        $readerName = 'assistant_catalog_guard_reader';
        config(['database.connections.'.$readerName => config('database.connections.'.$defaultName)]);
        \Illuminate\Support\Facades\DB::setDefaultConnection($readerName);
        $context = null;
        try {
            $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
            $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
            self::assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
            $lifecycle = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
            $context = new \App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext($lifecycle,
                new \App\BusinessModules\Features\AIAssistant\Models\AssistantRequest, $fixture->owner, 30000);
            app()->instance(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext::class, $context);
            $context->activate();
            $catalog = app(AssistantProjectReportCatalogReader::class);
            $result = $catalog->read($fixture->owner, (int) $fixture->organization->id, ['project_id' => (int) $project->id]);
            self::assertGreaterThan(0, $result['data']['total']);
            self::assertTrue($catalog->matchesReference($fixture->owner, (int) $fixture->organization->id, $result['source_refs'][0]));
            self::assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
            self::assertSame(0, \Illuminate\Support\Facades\DB::table('report_runs')->count());
        } finally {
            $context?->restoreDatabaseStatementTimeouts();
            app()->forgetInstance(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext::class);
            while (\Illuminate\Support\Facades\DB::connection($readerName)->transactionLevel() > 0) { \Illuminate\Support\Facades\DB::connection($readerName)->rollBack(); }
            \Illuminate\Support\Facades\DB::setDefaultConnection($defaultName);
            \Illuminate\Support\Facades\DB::purge($readerName);
            config(['database.connections.'.$readerName => null]);
        }
    }

    public function test_permission_cache_keeps_snapshot_and_reloads_in_next_transaction_with_identical_facts(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $defaultName = \Illuminate\Support\Facades\DB::getDefaultConnection();
        $readerName = 'assistant_abac_snapshot_reader';
        $writerName = 'assistant_abac_snapshot_writer';
        $configuration = config('database.connections.'.$defaultName);
        config(['database.connections.'.$readerName => $configuration, 'database.connections.'.$writerName => $configuration]);
        \Illuminate\Support\Facades\DB::setDefaultConnection($readerName);
        $connection = \Illuminate\Support\Facades\DB::connection($readerName);
        self::assertSame(0, $connection->transactionLevel());
        $writer = \Illuminate\Support\Facades\DB::connection($writerName);
        try {
            foreach (['assignment', 'role_definition'] as $change) {
                $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
                $fixture->memberRole->updateQuietly(['module_permissions' => ['reports' => ['reports.production_progress.view']]]);
                $facts = new \App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts('queue',
                    (int) $fixture->member->id, (int) $fixture->organization->id, null, null, new \DateTimeImmutable('2026-10-13T12:00:00.123456Z'));
                $evaluator = new \App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelCurrentReportAbacEvaluator;
                $connection->beginTransaction();
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                self::assertTrue($evaluator->evaluate((int) $fixture->member->id, 'reports.production_progress.view', $facts)->granted);
                if ($change === 'assignment') {
                    self::assertSame(1, $writer->table('user_role_assignments')->where('id', $fixture->memberAssignment->id)->update(['is_active' => false]));
                } else {
                    self::assertSame(1, $writer->table('organization_custom_roles')->where('id', $fixture->memberRole->id)->update(['module_permissions' => json_encode([])]));
                }
                self::assertTrue($evaluator->evaluate((int) $fixture->member->id, 'reports.production_progress.view', $facts)->granted);
                $connection->commit();
                $connection->beginTransaction();
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                self::assertFalse($evaluator->evaluate((int) $fixture->member->id, 'reports.production_progress.view', $facts)->granted);
                $connection->commit();
            }
        } finally {
            if ($connection->transactionLevel() > 0) { $connection->rollBack(); }
            \Illuminate\Support\Facades\DB::setDefaultConnection($defaultName);
            \Illuminate\Support\Facades\DB::purge($readerName);
            \Illuminate\Support\Facades\DB::purge($writerName);
            config(['database.connections.'.$readerName => null, 'database.connections.'.$writerName => null]);
        }
    }

    public function test_record_visible_safety_names_and_published_report_catalog_use_native_current_authorization(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $fixture->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $member = Model::withoutEvents(fn () => $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'], 'safety-management' => ['safety-management.view']]));
        $member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        Model::withoutEvents(fn () => SafetyViolation::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'created_by_user_id' => $fixture->owner->id, 'assigned_to_user_id' => $fixture->owner->id, 'violation_number' => 'OWNER-NAME', 'title' => 'Нарушение', 'severity' => 'high', 'status' => 'open']));
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canReadDomain($member, $org, 'people'));
        $args = ['project_id' => (int) $project->id];
        $safety = app(AssistantProjectSafetyReader::class); $result = $safety->read($member, $org, $args);
        self::assertSame([(int) $fixture->owner->id => $fixture->owner->name], $result['data']['sections']['violation']['owners']);
        self::assertStringNotContainsString($fixture->owner->email, json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertTrue($safety->matchesReference($member, $org, $result['source_refs'][0]));
        $fixture->owner->updateQuietly(['name' => 'Ответственный изменён']);
        self::assertFalse($safety->matchesReference($member, $org, $result['source_refs'][0]));
        $catalog = app(AssistantProjectReportCatalogReader::class);
        $reports = $catalog->read($fixture->owner, $org, $args);
        $auth = app(CurrentReportScopeAuthorizer::class)->authorizeCatalog((int) $fixture->owner->id, $org,
            new \DateTimeZone((string) config('app.timezone', 'UTC')), app(ReportHttpAuthorizationTargetResolver::class)->catalog());
        $native = app(GetReportCatalogAction::class)->handle($auth->context, $auth);
        self::assertGreaterThan(0, $reports['data']['total']);
        self::assertSame(count($native->definitions), $reports['data']['total']);
        self::assertLessThanOrEqual(20, count($reports['data']['definitions']));
        $nativeByCode = [];
        foreach ($native->definitions as $definition) { $nativeByCode[$definition->code] = $definition; }
        foreach ($reports['data']['definitions'] as $row) {
            self::assertArrayHasKey($row['code'], $nativeByCode);
            self::assertSame($nativeByCode[$row['code']]->visibility->canRun, $row['can_run']);
        }
        self::assertTrue($catalog->matchesReference($fixture->owner, $org, $reports['source_refs'][0]));
        $reportMember = Model::withoutEvents(fn () => $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'reports' => ['reports.production_progress.view']]));
        $reportMember->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canCurrentPermission($reportMember, $org, 'reports.view'));
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canCurrentPermission($reportMember, $org, 'reports.production_progress.view'));
        $memberReports = $catalog->read($reportMember, $org, $args);
        self::assertContains('accepted_production_progress', array_column($memberReports['data']['definitions'], 'code'));
        self::assertTrue($catalog->matchesReference($reportMember, $org, $memberReports['source_refs'][0]));
        $assignment = \App\Domain\Authorization\Models\UserRoleAssignment::query()->where('user_id', $reportMember->id)->firstOrFail();
        $role = \App\Domain\Authorization\Models\OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->firstOrFail();
        $role->updateQuietly(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]]);
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadDomain($reportMember, $org, 'assistant'));
        self::assertFalse($catalog->matchesReference($reportMember, $org, $memberReports['source_refs'][0]));
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('report_runs')->count());
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($catalog->matchesReference($fixture->owner, $org, $reports['source_refs'][0]));
    }
}
