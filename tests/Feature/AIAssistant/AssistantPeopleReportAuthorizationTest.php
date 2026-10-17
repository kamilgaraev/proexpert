<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantPeopleReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_directory_grants_enable_people_reports_and_fresh_revocation_closes_them(): void
    {
        Queue::fake();
        $fixture = AssistantRealAuthorizationFixture::create();
        $org = (int) $fixture->organization->id;
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => $org];
        self::assertTrue($authorization->canCurrent($fixture->owner, 'admin.users.view', $context));
        self::assertFalse($authorization->canCurrent($fixture->owner, 'users.view', $context));
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadDomain($fixture->owner, $org, 'people'));
        $modulePermissions = ['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate']];
        $literalReader = $fixture->addMember($modulePermissions, ['users.view', 'reports.view']);
        $nativeReader = $fixture->addMember($modulePermissions, ['admin.users.view', 'reports.view']);
        $question = 'Сделай Excel по всем сотрудникам нашей организации: имя, должность, статус и роль в системе. На втором листе — количество сотрудников по ролям. Если каких-то данных нет, оставь пустые поля.';
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($question);
        $hints = app(AssistantCustomReportSourceHints::class);
        $checker = app(AIPermissionChecker::class);
        $builder = app(AssistantCustomReportBuilder::class);
        $references = app(AssistantCustomReportReference::class);
        $plan = ['title' => 'Сотрудники', 'format' => 'xlsx', 'sections' => [
            ['title' => 'Сотрудники', 'domain' => 'people', 'entity_type' => 'user', 'project_id' => null,
                'columns' => ['id', 'name', 'position', 'is_active', 'primary_role_label'], 'filters' => [],
                'group_by' => [], 'metrics' => [], 'order_by' => [], 'row_limit' => 100],
            ['title' => 'По ролям', 'domain' => 'people', 'entity_type' => 'user', 'project_id' => null,
                'columns' => ['primary_role_label'], 'filters' => [], 'group_by' => ['primary_role_label'],
                'metrics' => [['field' => 'id', 'operation' => 'count', 'label' => 'Сотрудников']], 'order_by' => [], 'row_limit' => 100],
        ]];
        $memberIds = $fixture->organization->users()->pluck('users.id')->all();
        $snapshots = [];
        foreach ([$fixture->owner, $fixture->administrator, $literalReader, $nativeReader] as $actor) {
            $sources = array_column($hints->forRequest($actor, $org, $question, $understanding), null, 'domain');
            self::assertArrayHasKey('people', $sources);
            self::assertSame('string', $sources['people']['fields']['position']);
            self::assertSame('boolean', $sources['people']['fields']['is_active']);
            self::assertTrue($checker->canExecuteTool($actor, 'search_users'));
            self::assertTrue($checker->canExecuteTool($actor, 'generate_custom_report'));
            $report = $builder->build($plan, $actor, $org);
            self::assertEqualsCanonicalizing($memberIds, array_column($report['sections'][0]['rows'], 0));
            self::assertSame(count($memberIds), array_sum(array_column($report['sections'][1]['rows'], 1)));
            self::assertTrue($references->canReadReference($actor, $org, $report['source_refs'][0]));
            $snapshots[$actor->id] = $report['source_refs'][0];
        }
        foreach ([$fixture->member, $fixture->foreignOwner] as $actor) {
            self::assertFalse($policy->canReadDomain($actor, $org, 'people'));
            self::assertSame([], $hints->forRequest($actor, $org, $question, $understanding));
            self::assertFalse($references->canReadReference($actor, $org, $snapshots[$fixture->owner->id]));
        }
        self::assertFalse($checker->canExecuteTool($fixture->member, 'search_users'));
        $assignment = UserRoleAssignment::query()->where('user_id', $nativeReader->id)->firstOrFail();
        OrganizationCustomRole::query()->findOrFail($assignment->custom_role_id)->updateQuietly([
            'system_permissions' => ['reports.view'],
        ]);
        self::assertFalse($checker->canExecuteTool($nativeReader, 'search_users'));
        self::assertSame([], $hints->forRequest($nativeReader, $org, $question, $understanding));
        self::assertFalse($references->canReadReference($nativeReader, $org, $snapshots[$nativeReader->id]));
        $fixture->organization->users()->updateExistingPivot($literalReader->id, ['is_active' => false]);
        self::assertSame([], $hints->forRequest($literalReader, $org, $question, $understanding));
        self::assertFalse($references->canReadReference($literalReader, $org, $snapshots[$literalReader->id]));
    }
}
