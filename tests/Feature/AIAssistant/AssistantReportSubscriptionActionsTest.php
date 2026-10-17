<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSubscriptionManager;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantReportSubscriptionActionsTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private Project $project;

    private AssistantReportSubscriptionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->manager = app(AssistantReportSubscriptionManager::class);
    }

    public function test_confirmed_creation_is_private_project_scoped_and_replayed_once_with_fresh_permission_checks(): void
    {
        $registry = app(AIToolRegistry::class);
        self::assertNotNull($registry->getTool('create_report_subscription'));
        self::assertNotNull($registry->getTool('update_report_subscription'));
        $before = DB::table('report_saved_views')->count();
        [$service, $conversation, $preview] = $this->preview('create_report_subscription', $this->arguments(), 'Создай мне подписку на отчёт о принятой выработке каждую пятницу');
        self::assertSame($before, DB::table('report_saved_views')->count());
        self::assertStringNotContainsString('definition_hash', json_encode($preview['summary_items'], JSON_THROW_ON_ERROR));
        self::assertContains('Только вам, в уведомлениях МОСТ', array_column($preview['summary_items'], 'value'));
        $payload = ['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true];
        $first = $service->execute($payload, $this->fixture->organization->id, $this->fixture->owner, $conversation);
        $second = $service->execute($payload, $this->fixture->organization->id, $this->fixture->owner, $conversation);
        self::assertSame(\App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($first), \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($second));
        self::assertSame($before + 1, DB::table('report_saved_views')->count());
        $subscription = $this->own($first['result']['subscription_id']);
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);
        self::assertSame($this->project->id, $input->effectiveProjectId());
        self::assertArrayNotHasKey('project_id', $input->filters->values);
        $view = app(ReportSavedViewStore::class)->getVisible($this->fixture->organization->id, $this->fixture->owner->id, $subscription->savedViewId);
        self::assertSame('private', $view->visibility);
        self::assertFalse($view->isDefault);
        $this->fixture->ownerAssignment->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->execute($payload, $this->fixture->organization->id, $this->fixture->owner, $conversation);
    }

    public function test_pause_resume_and_update_use_core_and_copy_shared_view_without_mutating_it(): void
    {
        $result = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization);
        $id = $result['subscription_id'];
        $original = $this->own($id);
        DB::table('report_saved_views')->where('id', $original->savedViewId)->update(['visibility' => 'organization']);
        self::assertSame('paused', $this->manager->update(['subscription_id' => $id, 'operation' => 'pause'], $this->fixture->owner, $this->fixture->organization)['status']);
        self::assertSame('active', $this->manager->update(['subscription_id' => $id, 'operation' => 'resume'], $this->fixture->owner, $this->fixture->organization)['status']);
        $this->manager->update(['subscription_id' => $id, 'operation' => 'update', 'name' => 'Новое название',
            'frequency' => 'daily', 'local_time' => '10:30', 'columns' => ['recognized_on']], $this->fixture->owner, $this->fixture->organization);
        $updated = $this->own($id);
        self::assertSame('daily', $updated->frequency->value);
        self::assertNull($updated->weekday);
        self::assertNotSame($original->savedViewId, $updated->savedViewId);
        $views = app(ReportSavedViewStore::class);
        self::assertSame('Приёмка подписки', $views->getVisible($this->fixture->organization->id, $this->fixture->owner->id, $original->savedViewId)->name);
        self::assertSame('organization', $views->getVisible($this->fixture->organization->id, $this->fixture->owner->id, $original->savedViewId)->visibility);
        $copy = $views->getVisible($this->fixture->organization->id, $this->fixture->owner->id, $updated->savedViewId);
        self::assertSame('private', $copy->visibility);
        self::assertSame(['recognized_on'], $copy->columns);
    }

    public function test_stale_preview_cannot_overwrite_a_new_subscription_version(): void
    {
        $id = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization)['subscription_id'];
        [$service, $conversation, $preview] = $this->preview('update_report_subscription',
            ['subscription_id' => $id, 'operation' => 'update', 'local_time' => '10:00'], 'Измени время подписки на отчёт на 10:00');
        $this->manager->update(['subscription_id' => $id, 'operation' => 'pause'], $this->fixture->owner, $this->fixture->organization);
        $this->expectException(RuntimeException::class);
        $service->execute(['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true],
            $this->fixture->organization->id, $this->fixture->owner, $conversation);
    }

    public function test_foreign_owner_and_revoked_project_cannot_mutate_or_expose_subscription(): void
    {
        $id = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization)['subscription_id'];
        $checker = app(AIPermissionChecker::class);
        $arguments = ['subscription_id' => $id, 'operation' => 'pause'];
        self::assertFalse($checker->canExecuteTool($this->fixture->administrator, 'update_report_subscription', $arguments));
        self::assertFalse($checker->canExecuteTool($this->fixture->foreignOwner, 'update_report_subscription', $arguments));
        $this->fixture->owner->organizations()->updateExistingPivot($this->fixture->organization->id, ['project_access_mode' => 'assigned_projects']);
        self::assertFalse($checker->canExecuteTool($this->fixture->owner, 'update_report_subscription', $arguments));
        $this->expectException(\Throwable::class);
        $this->manager->update($arguments, $this->fixture->owner, $this->fixture->organization);
    }

    public function test_changed_shared_view_invalidates_preview_and_lock_keeps_actor_visibility(): void
    {
        $id = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization)['subscription_id'];
        $subscription = $this->own($id);
        [$service, $conversation, $preview] = $this->preview('update_report_subscription',
            ['subscription_id' => $id, 'operation' => 'update', 'local_time' => '10:00'], 'Измени время подписки на отчёт на 10:00');
        DB::table('report_saved_views')->where('id', $subscription->savedViewId)->update(['name' => 'Изменено после предпросмотра']);
        $views = app(ReportSavedViewStore::class);
        DB::transaction(function () use ($views, $subscription): void {
            self::assertSame($subscription->savedViewId, $views->lockVisible($this->fixture->organization->id, $this->fixture->owner->id, $subscription->savedViewId)->id);
        });
        try {
            DB::transaction(fn () => $views->lockVisible($this->fixture->organization->id, $this->fixture->administrator->id, $subscription->savedViewId));
            self::fail('Private view leaked');
        } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException) {
        }
        $this->expectException(RuntimeException::class);
        $service->execute(['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true],
            $this->fixture->organization->id, $this->fixture->owner, $conversation);
    }

    public function test_invalid_configuration_and_relative_period_roll_back_without_saved_views(): void
    {
        $before = DB::table('report_saved_views')->count();
        foreach ([['filters_json' => '{"organization_id":1}'], ['scope' => 'organization'], ['columns' => ['not_a_column']],
            ['columns' => ['row_key']], ['columns' => ['drill']],
            ['weekday' => 8], ['local_time' => '25:00'], ['period_mode' => 'whenever'], ['filters_json' => '[]'], ['filters_json' => '{"unknown":1}']] as $changes) {
            try {
                $arguments = array_replace($this->arguments(), $changes);
                if (($changes['scope'] ?? null) === 'organization') {
                    unset($arguments['project_id']);
                }
                $this->manager->create($arguments, $this->fixture->owner, $this->fixture->organization);
                self::fail('Invalid subscription accepted');
            } catch (\PHPUnit\Framework\AssertionFailedError $error) {
                throw $error;
            } catch (\Throwable) {
                self::assertSame($before, DB::table('report_saved_views')->count());
            }
        }
    }

    public function test_pause_does_not_silently_discard_configuration_changes(): void
    {
        $id = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization)['subscription_id'];
        $this->expectException(InvalidArgumentException::class);
        $this->manager->update(['subscription_id' => $id, 'operation' => 'pause', 'local_time' => '10:00'], $this->fixture->owner, $this->fixture->organization);
    }

    public function test_catalog_and_update_only_accept_columns_present_in_export_data(): void
    {
        $catalog = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionCatalogReader::class)
            ->read($this->fixture->owner, $this->fixture->organization->id, ['scope' => 'organization']);
        $definition = array_values(array_filter($catalog['data']['items'], static fn (array $item): bool => $item['code'] === 'accepted_production_progress'))[0];
        $columns = array_column($definition['columns'], 'id');
        self::assertCount(19, $columns);
        self::assertNotContains('row_key', $columns);
        self::assertNotContains('drill', $columns);
        $id = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization)['subscription_id'];
        $subscription = $this->own($id);
        $before = DB::table('report_saved_views')->count();
        foreach (['row_key', 'drill'] as $column) {
            try {
                $this->manager->update(['subscription_id' => $id, 'operation' => 'update', 'columns' => [$column]], $this->fixture->owner, $this->fixture->organization);
                self::fail('Non-exportable column accepted');
            } catch (InvalidArgumentException) {
                self::assertSame($before, DB::table('report_saved_views')->count());
                self::assertSame($subscription->executionInputBytes, $this->own($id)->executionInputBytes);
                self::assertSame($subscription->transitionVersion, $this->own($id)->transitionVersion);
            }
        }
    }

    public function test_legacy_period_can_be_paused_after_confirmation_and_resumed_without_reconfiguration(): void
    {
        $id = $this->manager->create($this->arguments(), $this->fixture->owner, $this->fixture->organization)['subscription_id'];
        $subscription = $this->own($id);
        $legacy = json_decode($subscription->executionInputBytes, true, flags: JSON_THROW_ON_ERROR);
        unset($legacy['period_policy_version']);
        $legacy['period_policy'] = ['mode' => 'whenever'];
        $bytes = \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($legacy);
        DB::table('report_subscriptions')->where('id', $id)->update([
            'period_policy_json' => json_encode($legacy['period_policy'], JSON_THROW_ON_ERROR),
            'execution_input_bytes' => $bytes, 'execution_input_sha256' => hash('sha256', $bytes),
        ]);
        $subscription = $this->own($id);
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);
        self::assertNull($input->periodPolicyVersion);
        app(\App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver::class)->runData($input, new \DateTimeImmutable);
        $context = app(\App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory::class)
            ->forSubscription($subscription, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation::MANAGE);
        self::assertSame('paused', app(\App\BusinessModules\Core\Reporting\Application\Subscriptions\PauseReportSubscriptionHandler::class)->handle($context, $id)->status->value);
        app(\App\BusinessModules\Core\Reporting\Application\Subscriptions\ResumeReportSubscriptionHandler::class)->handle($context, $id);
        $before = DB::table('report_saved_views')->count();

        [$service, $conversation, $preview] = $this->preview('update_report_subscription',
            ['subscription_id' => $id, 'operation' => 'pause'], 'Приостанови мою подписку на отчёт');
        self::assertContains(trans_message('ai_report_subscriptions.legacy_period'), array_column($preview['summary_items'], 'value'));
        $service->execute(['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true],
            $this->fixture->organization->id, $this->fixture->owner, $conversation);
        self::assertSame('paused', $this->own($id)->status->value);
        self::assertSame('active', $this->manager->update(['subscription_id' => $id, 'operation' => 'resume'], $this->fixture->owner, $this->fixture->organization)['status']);
        self::assertSame($bytes, $this->own($id)->executionInputBytes);
        self::assertSame($before, DB::table('report_saved_views')->count());
    }

    private function arguments(): array
    {
        return ['report_code' => 'accepted_production_progress', 'name' => 'Приёмка подписки', 'scope' => 'project', 'project_id' => $this->project->id,
            'columns' => ['accepted_quantity', 'recognized_on'], 'frequency' => 'weekly', 'weekday' => 5,
            'local_time' => '09:00', 'timezone' => 'Europe/Moscow', 'period_mode' => 'previous_week', 'format' => 'xlsx'];
    }

    private function own(string $id): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription
    {
        return app(ReportSubscriptionStore::class)->getForActor($this->fixture->organization->id, $this->fixture->owner->id, $id);
    }

    private function preview(string $tool, array $arguments, string $message): array
    {
        $conversation = Conversation::query()->create(['organization_id' => $this->fixture->organization->id, 'user_id' => $this->fixture->owner->id, 'context' => []]);
        $requestId = (string) Str::uuid();
        $proposal = ['tool_name' => $tool, 'arguments' => $arguments, 'allowed' => true];
        $conversation->messages()->create(['role' => 'user', 'content' => $message, 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $this->fixture->owner->id,
            'request' => ['message' => $message, 'allow_actions' => true, 'context' => [], 'profile' => 'normal'],
        ]]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Предлагаю действие', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $this->fixture->owner->id, 'proposed_actions' => [$proposal],
        ]]);
        $service = app(AssistantActionService::class);

        return [$service, $conversation, $service->preview($proposal, $this->fixture->organization->id, $this->fixture->owner, $conversation)];
    }
}
