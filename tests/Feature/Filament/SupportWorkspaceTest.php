<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\SupportRequestResource;
use App\Models\Activity\ActivityEvent;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Policies\SystemAdmin\SupportRequestPolicy;
use App\Services\Filament\SupportWorkspaceService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SupportWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_support_roles_have_scoped_access_to_support_workspace(): void
    {
        $this->assertTrue(class_exists(SupportRequestResource::class), 'Support workspace resource is missing.');
        $this->assertTrue(class_exists(SupportRequestPolicy::class), 'Support workspace policy is missing.');

        $viewer = $this->actingAsRole('support_viewer');
        $request = $this->supportRequest();
        $policy = app(SupportRequestPolicy::class);

        $this->assertTrue(SupportRequestResource::canViewAny());
        $this->assertTrue($policy->view($viewer, $request));
        $this->assertFalse(SupportRequestResource::canCreate());
        $this->assertFalse(SupportRequestResource::canEdit($request));
        $this->assertFalse($policy->assign($viewer, $request));
        $this->assertFalse($policy->changeStatus($viewer, $request));
        $this->assertFalse($policy->addInternalNote($viewer, $request));
        $this->assertFalse($policy->linkOrganization($viewer, $request));
        $this->assertFalse($policy->escalate($viewer, $request));

        $operator = $this->actingAsRole('support_operator');

        $this->assertTrue(SupportRequestResource::canViewAny());
        $this->assertTrue($policy->assign($operator, $request));
        $this->assertTrue($policy->changeStatus($operator, $request));
        $this->assertTrue($policy->addInternalNote($operator, $request));
        $this->assertTrue($policy->linkOrganization($operator, $request));
        $this->assertTrue($policy->escalate($operator, $request));
        $this->assertFalse(SupportRequestResource::canDelete($request));
        $this->assertFalse(SupportRequestResource::canDeleteAny());

        Auth::guard('system_admin')->logout();
        $this->actingAs(User::factory()->create());

        $this->assertFalse(SupportRequestResource::canViewAny());
    }

    public function test_support_operations_update_request_and_record_audit_events(): void
    {
        $this->assertTrue(class_exists(SupportWorkspaceService::class), 'Support workspace service is missing.');

        $actor = SystemAdmin::factory()->role('support_operator')->create(['is_active' => true]);
        $assignee = SystemAdmin::factory()->role('support_operator')->create(['is_active' => true]);
        $organization = Organization::factory()->create(['name' => 'Support Target']);
        $request = $this->supportRequest();

        $service = app(SupportWorkspaceService::class);

        $service->assign($request, $assignee->id, $actor);
        $service->changeStatus($request, ContactForm::STATUS_PROCESSING, $actor);
        $service->addInternalNote($request, 'Проверили обращение и запросили уточнение у клиента.', $actor);
        $service->linkOrganization($request, $organization->id, $actor);
        $service->escalate($request, $actor);

        $request->refresh();

        $this->assertSame($assignee->id, $request->assigned_system_admin_id);
        $this->assertSame(ContactForm::STATUS_PROCESSING, $request->status);
        $this->assertSame($organization->id, $request->organization_id);
        $this->assertSame(ContactForm::PRIORITY_URGENT, $request->priority);
        $this->assertNotNull($request->last_activity_at);
        $this->assertNotNull($request->escalated_at);
        $this->assertSame($actor->id, $request->escalated_by_system_admin_id);
        $this->assertCount(1, $request->internal_notes);
        $this->assertSame($actor->id, $request->internal_notes[0]['author_system_admin_id']);

        foreach ([
            'system_admin.support.assigned',
            'system_admin.support.status_changed',
            'system_admin.support.internal_note_added',
            'system_admin.support.organization_linked',
            'system_admin.support.escalated',
        ] as $eventType) {
            $this->assertDatabaseHas('activity_events', [
                'event_type' => $eventType,
                'actor_type' => 'system_admin',
                'subject_type' => ContactForm::class,
                'subject_id' => $request->id,
            ]);
        }

        $this->assertSame(5, ActivityEvent::query()
            ->where('subject_type', ContactForm::class)
            ->where('subject_id', $request->id)
            ->count());
    }

    public function test_private_support_technical_fields_are_visible_only_to_managers(): void
    {
        $this->assertTrue(class_exists(SupportRequestResource::class), 'Support workspace resource is missing.');

        $source = (string) file_get_contents(app_path('Filament/Resources/SupportRequestResource.php'));

        $this->assertStringContainsString("Section::make(trans_message('support_workspace.sections.technical'))", $source);
        $this->assertMatchesRegularExpression(
            "/support_workspace\\.sections\\.technical[\\s\\S]+->visible\\(fn \\(\\): bool => self::canManageSupport\\(\\)\\)/",
            $source,
        );
        $this->assertStringContainsString('telegram_data', $source);
        $this->assertStringContainsString('utm_source', $source);
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = SystemAdmin::factory()->role($role)->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        return $admin;
    }

    private function supportRequest(): ContactForm
    {
        return ContactForm::query()->create([
            'name' => 'Ирина Клиентова',
            'email' => 'client@example.test',
            'phone' => '+79990000000',
            'company' => 'Client Company',
            'company_role' => 'customer',
            'company_size' => '10-50',
            'subject' => 'Не открывается кабинет',
            'message' => 'Клиент не может открыть кабинет после оплаты подписки.',
            'consent_to_personal_data' => true,
            'consent_version' => 'test-v1',
            'page_source' => 'customer-portal',
            'utm_source' => 'email',
            'status' => ContactForm::STATUS_NEW,
            'telegram_data' => [
                'organization_id' => 100,
                'user_id' => 200,
            ],
            'is_processed' => false,
        ]);
    }
}
