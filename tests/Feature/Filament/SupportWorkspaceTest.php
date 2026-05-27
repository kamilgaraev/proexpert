<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\SupportRequestResource;
use App\Mail\SupportTicketReplyMail;
use App\Models\Activity\ActivityEvent;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Policies\SystemAdmin\SupportRequestPolicy;
use App\Services\Filament\SupportWorkspaceService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $this->assertTrue($policy->reply($operator, $request));
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
        $this->assertSame('internal_note', $request->internal_notes[0]['type']);
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

    public function test_support_workspace_lists_only_customer_portal_tickets(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/SupportRequestResource.php'));

        $this->assertStringContainsString('customerPortalTickets()', $source);
        $this->assertStringNotContainsString("Tables\\Filters\\SelectFilter::make('channel')", $source);

        $portalTicket = $this->supportRequest([
            'subject' => 'Нужна помощь в кабинете',
            'channel' => ContactForm::CHANNEL_CUSTOMER_PORTAL,
            'page_source' => 'customer-portal',
        ]);
        $siteLead = $this->supportRequest([
            'subject' => 'Запрос демонстрации',
            'channel' => ContactForm::CHANNEL_PUBLIC_FORM,
            'page_source' => 'landing-demo',
        ]);
        $manualRequest = $this->supportRequest([
            'subject' => 'Внутренняя ручная запись',
            'channel' => ContactForm::CHANNEL_MANUAL,
            'page_source' => 'manual',
        ]);

        $visibleIds = ContactForm::query()
            ->customerPortalTickets()
            ->pluck('id')
            ->all();

        $this->assertContains($portalTicket->id, $visibleIds);
        $this->assertNotContains($siteLead->id, $visibleIds);
        $this->assertNotContains($manualRequest->id, $visibleIds);
    }

    public function test_support_resource_base_query_excludes_public_leads(): void
    {
        $portalTicket = $this->supportRequest([
            'subject' => 'РќСѓР¶РЅР° РїРѕРјРѕС‰СЊ РІ РєР°Р±РёРЅРµС‚Рµ',
            'channel' => ContactForm::CHANNEL_CUSTOMER_PORTAL,
            'page_source' => 'customer-portal',
        ]);
        $siteLead = $this->supportRequest([
            'subject' => 'Р—Р°РїСЂРѕСЃ РґРµРјРѕРЅСЃС‚СЂР°С†РёРё',
            'channel' => ContactForm::CHANNEL_PUBLIC_FORM,
            'page_source' => 'landing-demo',
        ]);
        $manualRequest = $this->supportRequest([
            'subject' => 'Р’РЅСѓС‚СЂРµРЅРЅСЏСЏ СЂСѓС‡РЅР°СЏ Р·Р°РїРёСЃСЊ',
            'channel' => ContactForm::CHANNEL_MANUAL,
            'page_source' => 'manual',
        ]);

        $visibleIds = SupportRequestResource::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains($portalTicket->id, $visibleIds);
        $this->assertNotContains($siteLead->id, $visibleIds);
        $this->assertNotContains($manualRequest->id, $visibleIds);
    }

    public function test_support_operator_can_reply_to_customer_and_record_ticket_history(): void
    {
        Mail::fake();

        $actor = SystemAdmin::factory()->role('support_operator')->create([
            'is_active' => true,
            'name' => 'Support Operator',
        ]);
        $request = $this->supportRequest([
            'email' => 'customer-ticket@example.test',
            'subject' => 'Не открывается акт',
            'message' => 'В кабинете не открывается акт выполненных работ.',
            'status' => ContactForm::STATUS_NEW,
            'is_processed' => false,
        ]);

        app(SupportWorkspaceService::class)->replyToCustomer(
            supportRequest: $request,
            subject: 'Re: Не открывается акт',
            body: 'Проверили доступ и обновили права. Попробуйте открыть акт еще раз.',
            actor: $actor,
        );

        $request->refresh();

        Mail::assertSent(SupportTicketReplyMail::class, function (SupportTicketReplyMail $mail): bool {
            $mail->assertHasSubject('Ответ поддержки: Re: Не открывается акт');

            return $mail->hasTo('customer-ticket@example.test')
                && $mail->bodyText === 'Проверили доступ и обновили права. Попробуйте открыть акт еще раз.'
                && $mail->requestSubject === 'Не открывается акт';
        });

        $this->assertSame(ContactForm::STATUS_PROCESSING, $request->status);
        $this->assertTrue($request->is_processed);
        $this->assertNotNull($request->processed_at);
        $this->assertNotNull($request->last_activity_at);
        $this->assertCount(1, $request->internal_notes);
        $this->assertSame('customer_reply', $request->internal_notes[0]['type']);
        $this->assertSame('customer-ticket@example.test', $request->internal_notes[0]['sent_to']);
        $this->assertSame($actor->id, $request->internal_notes[0]['author_system_admin_id']);

        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'system_admin.support.customer_replied',
            'actor_type' => 'system_admin',
            'subject_type' => ContactForm::class,
            'subject_id' => $request->id,
        ]);
    }

    public function test_reply_email_is_not_sent_when_ticket_history_cannot_be_saved(): void
    {
        Mail::fake();

        $actor = SystemAdmin::factory()->role('support_operator')->create([
            'is_active' => true,
            'name' => 'Support Operator',
        ]);
        $request = $this->supportRequest([
            'email' => 'stale-ticket@example.test',
            'subject' => 'РўРёРєРµС‚ СѓРґР°Р»РµРЅ РґРѕ РѕС‚РІРµС‚Р°',
        ]);
        DB::table($request->getTable())
            ->where('id', $request->id)
            ->delete();

        try {
            app(SupportWorkspaceService::class)->replyToCustomer(
                supportRequest: $request,
                subject: 'Re: РўРёРєРµС‚ СѓРґР°Р»РµРЅ РґРѕ РѕС‚РІРµС‚Р°',
                body: 'РџРёСЃСЊРјРѕ РЅРµ РґРѕР»Р¶РЅРѕ СѓР№С‚Рё, РµСЃР»Рё РёСЃС‚РѕСЂРёСЏ РѕР±СЂР°С‰РµРЅРёСЏ РЅРµ СЃРѕС…СЂР°РЅРёР»Р°СЃСЊ.',
                actor: $actor,
            );

            $this->fail('ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException) {
            Mail::assertNothingSent();
        }
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = SystemAdmin::factory()->role($role)->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        return $admin;
    }

    private function supportRequest(array $overrides = []): ContactForm
    {
        return ContactForm::query()->create(array_merge([
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
        ], $overrides));
    }
}
