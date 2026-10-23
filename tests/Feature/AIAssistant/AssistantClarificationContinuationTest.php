<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use PHPUnit\Framework\Attributes\DataProvider;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantClarificationContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantEgressFixtureService;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantClarificationContinuationTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private int $parentEstimated = 0;

    private Project $project;

    private ScheduleTask $task;

    private User $operator;

    private OrdinaryAssistantEnrollmentService $enrollments;

    private const ACCOUNT_CANARY = 'PRIVATE_ACCOUNT_NAME_NOT_APPROVED';
    private const HISTORY_CANARY = 'FREE_HISTORY_NOT_ADMITTED';
    private const QUESTION = 'Поясни, что означают учебные данные выбранного проекта.';
    private const REPLY = 'Объясни учебные риски графика.';
    private const ANSWER = 'Учебный график требует внимания к срокам.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        config(['logging.default' => 'stderr', 'ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3], 'cache.default' => 'array', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT_CANARY]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'ERP_RESULT_NOT_HISTORY', 'is_archived' => false]));
        $schedule = ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'Учебный график', 'status' => 'active',
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20']));
        $this->task = ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create(['organization_id' => $this->fixture->organization->id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->fixture->owner->id, 'assigned_user_id' => $this->fixture->owner->id,
            'name' => 'Учебная задача', 'status' => 'not_started', 'task_type' => 'task', 'progress_percent' => 0,
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20', 'planned_duration_days' => 19]));
        $this->operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'scoped-egress-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_a_new_owner_preview_binds_only_the_parent_question_and_fixed_clarification(): void
    {
        [$cohort, $parent, $oldManifest, $oldInput] = $this->parent(true);
        $legacyAdmission = AssistantEgressEnrollment::query()->findOrFail($oldManifest['enrollment_id']);
        $legacyAdmission->forceFill(['declaration_version' => OrdinaryAssistantEnrollmentService::LEGACY_OWNER_DECLARATION])->save();
        $request = AssistantRequest::query()->where('request_id', $parent['request_id'])->sole();
        self::assertNull($request->payload);
        self::assertNull($oldInput['conversation_id']);
        self::assertNotSame($request->request_hash, app(AICreditService::class)->canonicalAssistantRequest(array_replace($oldInput, ['conversation_id' => $parent['conversation_id']])));
        self::assertSame($request->request_hash, $request->clarification_receipt['original_request_hash']);
        self::assertStringNotContainsString(self::QUESTION, json_encode($request->clarification_receipt, JSON_THROW_ON_ERROR));
        $conversation = Conversation::query()->findOrFail($parent['conversation_id']);
        app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)->addMessage($conversation, 'assistant', self::HISTORY_CANARY);
        $conversation->forceFill(['context' => ['private_context' => self::HISTORY_CANARY]])->save();
        $payload = $this->childInput($parent);
        $manifest = $this->preview($cohort, $payload);
        self::assertSame(['version' => OrdinaryAssistantClarificationContext::VERSION, 'previous_user_question' => self::QUESTION,
            'clarification' => trans_message('ai_assistant.clarification_goal'), 'current_reply' => self::REPLY], $manifest['input_context']);
        self::assertNotSame($oldManifest['input_hash'], $manifest['input_hash']);
        self::assertNotSame($oldManifest['manifest_hash'], $manifest['manifest_hash']);
        $this->confirm($cohort, $manifest);
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $manifest, $parent): array {
            self::assertSame('auto', $options['tool_choice']);
            self::assertSame(['get_project_attention'], array_column($options['tools'], 'name'));
            self::assertGreaterThan($this->parentEstimated, $options['estimated_input_tokens']);
            self::assertSame(['system', 'user', 'user', 'user'], array_column(array_slice($input, 0, 4), 'role'));
            self::assertSame(['kind' => 'approved_clarification_context', 'version' => OrdinaryAssistantClarificationContext::VERSION,
                'previous_user_question' => self::QUESTION, 'clarification' => trans_message('ai_assistant.clarification_goal')], json_decode($input[2]['content'], true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(self::REPLY, $input[3]['content']);
            self::assertStringNotContainsString(self::HISTORY_CANARY, json_encode($input, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString(self::ACCOUNT_CANARY, json_encode($input, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($parent['request_id'], json_encode($input, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('parent_enrollment_id', json_encode($input, JSON_THROW_ON_ERROR));
            if (++$calls === 1) {
                self::assertCount(4, $input);
                self::assertStringNotContainsString('ERP_RESULT_NOT_HISTORY', json_encode($input, JSON_THROW_ON_ERROR));
                return $this->nativeCall('child');
            }
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
            self::assertCount(1, $outputs);
            self::assertSame('call_child', $outputs[0]['call_id']);
            self::assertSame($manifest['sources'][0]['projection'], json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
            return $this->answer(self::ANSWER);
        });
        $result = $this->ask($cohort, $payload, $provider);
        self::assertSame(2, $calls);
        self::assertSame(self::ANSWER, $result['message']['content']);
        self::assertFalse($result['message']['metadata']['needs_clarification']);
        self::assertNotEmpty($result['message']['metadata']['source_refs']);
        $child = AssistantRequest::query()->where('request_id', $result['request_id'])->sole();
        self::assertNull($child->clarification_receipt);
        self::assertSame(2, $child->calls_used);
        self::assertSame(2, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $child->reservation_id)->count());
        self::assertSame(2, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->count());
        self::assertSame(OrdinaryAssistantEnrollmentService::LEGACY_OWNER_DECLARATION, $legacyAdmission->refresh()->declaration_version);
    }

    public static function invalidParents(): iterable
    {
        foreach (['failed', 'cancelled', 'question changed', 'question deleted', 'assistant changed', 'receipt missing',
            'receipt enum changed', 'receipt malformed', 'original hash changed', 'user actor changed', 'foreign organization', 'foreign author', 'different project', 'different conversation', 'generation changed', 'viewer only'] as $kind) { yield $kind => [$kind]; }
    }

    #[DataProvider('invalidParents')]
    public function test_parent_metadata_or_old_ids_cannot_create_continuation_authority(string $kind): void
    {
        [$cohort, $parent] = $this->parent();
        $payload = $this->childInput($parent);
        $request = AssistantRequest::query()->where('request_id', $parent['request_id'])->sole();
        $receipt = $request->clarification_receipt;
        match ($kind) {
            'failed' => $request->update(['status' => 'failed']),
            'cancelled' => $request->update(['cancel_requested_at' => now()]),
            'question changed' => Message::query()->whereKey($receipt['user_message_id'])->update(['content' => 'Changed parent']),
            'question deleted' => Message::query()->whereKey($receipt['user_message_id'])->delete(),
            'assistant changed' => Message::query()->whereKey($receipt['assistant_message_id'])->update(['content' => self::HISTORY_CANARY]),
            'receipt missing' => $request->update(['clarification_receipt' => null]),
            'receipt enum changed' => $request->update(['clarification_receipt' => array_replace($receipt, ['missing' => 'focus'])]),
            'receipt malformed' => $request->update(['clarification_receipt' => array_diff_key($receipt, ['user_message_id' => true]) + ['untrusted' => 100]]),
            'original hash changed' => $request->update(['request_hash' => str_repeat('f', 64)]),
            'user actor changed' => Message::query()->findOrFail($receipt['user_message_id'])->forceFill(['metadata' => ['actor_user_id' => $this->operator->id, 'request_id' => $parent['request_id']]])->save(),
            'foreign organization' => $request->update(['organization_id' => \App\Models\Organization::factory()->create()->id]),
            'foreign author' => $request->update(['user_id' => $this->operator->id]),
            'different project' => $cohort->update(['project_id' => Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $cohort->organization_id]))->id]),
            'different conversation' => $payload = $this->quote(array_replace($payload, ['request_id' => (string) Str::uuid(), 'conversation_id' => app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)->createConversation($cohort->organization_id, $this->fixture->owner)->id])),
            'generation changed' => $cohort->update(['generation' => (string) Str::uuid()]),
            'viewer only' => $this->makeViewer($parent['conversation_id']),
        };
        $this->expectException(AssistantEgressDenied::class);
        $this->preview($cohort, $payload);
    }

    public static function invalidAdmissions(): iterable
    {
        foreach (['unconfirmed', 'expired', 'revoked', 'old permit', 'reply changed', 'parent request id'] as $kind) { yield $kind => [$kind]; }
    }

    #[DataProvider('invalidAdmissions')]
    public function test_the_child_requires_a_new_exact_current_owner_admission(string $kind): void
    {
        [$cohort, $parent] = $this->parent();
        $payload = $this->childInput($parent);
        if ($kind !== 'old permit') {
            $manifest = $this->preview($cohort, $payload);
            if ($kind !== 'unconfirmed') { $this->confirm($cohort, $manifest); }
            if ($kind === 'expired') { AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['expires_at' => now()->subMinute()]); }
            if ($kind === 'revoked') { $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']); }
        }
        if ($kind === 'reply changed') { $payload['message'] = 'Different current reply'; }
        if ($kind === 'parent request id') { $payload['request_id'] = $parent['request_id']; }
        $provider = $this->responses(static function (): never { self::fail('Unapproved continuation reached wire'); });
        $this->expectException(AssistantEgressDenied::class);
        $this->ask($cohort, $payload, $provider);
    }

    public function test_a_parent_quote_cannot_approve_new_continuation_bytes(): void
    {
        [$cohort, $parent, , $oldPayload] = $this->parent();
        $payload = $this->childInput($parent);
        $payload['quote_id'] = $oldPayload['quote_id'];
        $this->expectException(AssistantEgressDenied::class);
        $this->preview($cohort, $payload);
    }
    public function test_a_historical_parent_ttl_is_not_reused_and_a_child_cannot_start_a_second_hop(): void
    {
        [$cohort, $parent, $oldManifest] = $this->parent();
        AssistantEgressEnrollment::query()->whereKey($oldManifest['enrollment_id'])->update(['expires_at' => now()->subHour()]);
        $payload = $this->childInput($parent);
        $manifest = $this->preview($cohort, $payload);
        $this->confirm($cohort, $manifest);
        $calls = 0;
        $provider = $this->responses(function (array $input) use (&$calls): array {
            self::assertSame(self::QUESTION, json_decode($input[2]['content'], true, 512, JSON_THROW_ON_ERROR)['previous_user_question']);
            $calls++;
            return $this->answer('{"kind":"clarification","missing":"focus"}');
        });
        $child = $this->ask($cohort, $payload, $provider);
        self::assertSame(1, $calls);
        self::assertTrue($child['message']['metadata']['needs_clarification']);
        self::assertArrayNotHasKey(OrdinaryAssistantClarificationContext::KEY, $child['message']['metadata']);
        self::assertNull(AssistantRequest::query()->where('request_id', $child['request_id'])->sole()->clarification_receipt);
        $fake = $child;
        $fake['message']['metadata'][OrdinaryAssistantClarificationContext::KEY] = ['version' => OrdinaryAssistantClarificationContext::VERSION,
            'parent_request_id' => $child['request_id'], 'context_hash' => str_repeat('a', 64)];
        $this->expectException(AssistantEgressDenied::class);
        $this->preview($cohort, $this->childInput($fake));
    }

    public static function changedBoundaries(): iterable
    {
        foreach (['confirm', 'next send', 'publication', 'source', 'ACL'] as $phase) { yield $phase => [$phase]; }
    }

    #[DataProvider('changedBoundaries')]
    public function test_parent_or_source_freshness_is_checked_before_confirmation_send_and_publication(string $phase): void
    {
        [$cohort, $parent] = $this->parent();
        $payload = $this->childInput($parent);
        $manifest = $this->preview($cohort, $payload);
        $receipt = AssistantRequest::query()->where('request_id', $parent['request_id'])->sole()->clarification_receipt;
        if ($phase === 'confirm') {
            Message::query()->whereKey($receipt['user_message_id'])->update(['content' => 'Changed before confirmation']);
            $this->expectException(AssistantEgressDenied::class);
            $this->confirm($cohort, $manifest);
            return;
        }
        $this->confirm($cohort, $manifest);
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $phase, $receipt): array {
            $calls++;
            if ($phase === 'source') { $this->task->updateQuietly(['name' => 'Changed approved source']); }
            elseif ($phase === 'ACL') { UserRoleAssignment::query()->where('user_id', $this->fixture->owner->id)->update(['is_active' => false]); }
            elseif ($phase === 'next send' || $calls === 2) { Message::query()->whereKey($receipt['user_message_id'])->update(['content' => 'Changed after approved preview']); }
            return $calls === 1 ? $this->nativeCall('freshness') : $this->answer(self::ANSWER);
        });
        try {
            $this->ask($cohort, $payload, $provider);
            self::fail('A changed continuation boundary was published');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('assistant_egress_denied', $denied->getMessage());
            self::assertSame($phase === 'publication' ? 2 : 1, $calls);
            self::assertSame(1, Message::query()->where('conversation_id', $parent['conversation_id'])->where('role', 'assistant')->count());
        }
    }

    public function test_a_new_two_call_cap_preserves_both_child_usage_rows_and_parent_usage(): void
    {
        [$cohort, $parent] = $this->parent();
        config(['ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 2]]);
        $payload = $this->childInput($parent);
        $manifest = $this->preview($cohort, $payload);
        $this->confirm($cohort, $manifest);
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls): array {
            self::assertLessThan(2, $calls);
            self::assertSame(2, $options['budget_limits']['max_calls']);
            self::assertCount($calls, array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
            return $this->nativeCall('budget_'.++$calls);
        });
        $result = $this->ask($cohort, $payload, $provider);
        self::assertSame(2, $calls);
        $child = AssistantRequest::query()->where('request_id', $result['request_id'])->sole();
        self::assertSame(2, $child->max_calls);
        self::assertSame(2, $child->calls_used);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $child->reservation_id)->get();
        self::assertCount(2, $usage);
        self::assertSame(200, (int) $usage->sum(static fn ($row): int => (int) $row->metadata['input_tokens']));
        self::assertSame(40, (int) $usage->sum(static fn ($row): int => (int) $row->metadata['output_tokens']));
        $old = AssistantRequest::query()->where('request_id', $parent['request_id'])->sole();
        self::assertSame(1, $old->calls_used);
        self::assertSame(1, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $old->reservation_id)->count());
        self::assertTrue($result['message']['metadata']['needs_clarification']);
    }

    public function test_a_continuation_envelope_is_denied_outside_an_assigned_guarded_cohort(): void
    {
        [$cohort, $parent] = $this->parent();
        $payload = $this->childInput($parent);
        $cohort->delete();
        $provider = $this->responses(static function (): never { self::fail('Continuation fell back to legacy transport'); });
        $this->expectException(AssistantEgressDenied::class);
        $this->ask($cohort, $payload, $provider);
    }

    public function test_parent_length_is_rejected_without_a_historical_question_truncation(): void
    {
        $question = str_repeat('я', 2001);
        [$cohort, $parent] = $this->parent(question: $question);
        self::assertArrayNotHasKey(OrdinaryAssistantClarificationContext::KEY, $parent['message']['metadata']);
        self::assertNull(AssistantRequest::query()->where('request_id', $parent['request_id'])->sole()->clarification_receipt);
        self::assertSame($question, Message::query()->where('conversation_id', $parent['conversation_id'])->where('role', 'user')->sole()->content);
        $parent['message']['metadata'][OrdinaryAssistantClarificationContext::KEY] = ['version' => OrdinaryAssistantClarificationContext::VERSION,
            'parent_request_id' => $parent['request_id'], 'context_hash' => str_repeat('a', 64)];
        $this->expectException(AssistantEgressDenied::class);
        $this->preview($cohort, $this->childInput($parent));
    }

    public function test_chat_api_does_not_allow_a_client_to_write_the_server_receipt(): void
    {
        [$cohort, $parent] = $this->parent();
        $payload = $this->quote(['request_id' => (string) Str::uuid(), 'message' => 'Новый самостоятельный вопрос.',
            'conversation_id' => $parent['conversation_id'], 'profile' => 'short', 'allow_actions' => false]);
        $manifest = $this->preview($cohort, $payload);
        $this->confirm($cohort, $manifest);
        $forged = AssistantRequest::query()->where('request_id', $parent['request_id'])->sole()->clarification_receipt;
        $this->withoutMiddleware()->postJson('/api/v1/admin/ai-assistant/chat', $payload + ['clarification_receipt' => $forged,
            'metadata' => [OrdinaryAssistantClarificationContext::KEY => $parent['message']['metadata'][OrdinaryAssistantClarificationContext::KEY]]])->assertStatus(202);
        $queued = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
        self::assertNull($queued->clarification_receipt);
        self::assertArrayNotHasKey('clarification_receipt', $queued->payload);
        self::assertArrayNotHasKey('metadata', $queued->payload);
        $request = AssistantRequest::query()->where('request_id', $parent['request_id'])->sole();
        $request->update(['clarification_receipt' => null]);
        $this->expectException(AssistantEgressDenied::class);
        $this->preview($cohort, $this->childInput($parent));
    }

    public static function invalidOutcomes(): iterable
    {
        yield 'free question' => ['Какой период вас интересует?'];
        yield 'additional field' => ['{"kind":"clarification","missing":"goal","question":"Free assistant text"}'];
        yield 'financial field' => ['{"kind":"clarification","missing":"period","amount":123}'];
    }

    #[DataProvider('invalidOutcomes')]
    public function test_free_or_untyped_parent_answers_do_not_receive_a_server_receipt(string $text): void
    {
        [$cohort, $parent] = $this->parent(raw: $text);
        self::assertNull(AssistantRequest::query()->where('request_id', $parent['request_id'])->sole()->clarification_receipt);
        self::assertArrayNotHasKey(OrdinaryAssistantClarificationContext::KEY, $parent['message']['metadata']);
        $parent['message']['metadata'][OrdinaryAssistantClarificationContext::KEY] = ['version' => OrdinaryAssistantClarificationContext::VERSION,
            'parent_request_id' => $parent['request_id'], 'context_hash' => str_repeat('a', 64)];
        $this->expectException(AssistantEgressDenied::class);
        $this->preview($cohort, $this->childInput($parent));
    }

    public function test_the_maximum_parent_question_is_previewed_and_sent_without_hidden_truncation(): void
    {
        $question = self::QUESTION.str_repeat('x', 2000 - mb_strlen(self::QUESTION));
        [$cohort, $parent] = $this->parent(question: $question);
        $payload = $this->childInput($parent);
        $manifest = $this->preview($cohort, $payload);
        self::assertSame($question, $manifest['input_context']['previous_user_question']);
        $this->confirm($cohort, $manifest);
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $question): array {
            self::assertSame($question, json_decode($input[2]['content'], true, 512, JSON_THROW_ON_ERROR)['previous_user_question']);
            self::assertLessThanOrEqual(12288, $options['estimated_input_tokens']);
            self::assertGreaterThan($this->parentEstimated, $options['estimated_input_tokens']);
            $calls++;
            return $this->answer('{"kind":"clarification","missing":"period"}');
        });
        $this->ask($cohort, $payload, $provider);
        self::assertSame(1, $calls);
    }

    public function test_an_owner_reviewer_cannot_preview_a_private_parent_question_from_a_forwarded_locator(): void
    {
        [$cohort, $parent, $parentManifest] = $this->parent();
        $reviewer = $this->otherOwner();
        $this->enrollments->assertOrganizationManager((int) $reviewer->id, $cohort->organization_id);
        $sourceReview = app(\App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog::class)
            ->captureForReview($this->fixture->owner, (int) $reviewer->id, $cohort->organization_id, $this->project->id, 'get_project_attention', $this->args());
        self::assertSame($parentManifest['sources'][0]['projection_hash'], $sourceReview['proof']['projection_hash']);
        self::assertNull(app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)
            ->findAccessibleConversation($parent['conversation_id'], $reviewer, $cohort->organization_id));
        $payload = $this->childInput($parent);
        $before = AssistantEgressEnrollment::query()->count();
        try {
            $manifest = $this->enrollments->preview($reviewer, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
                [['tool' => 'get_project_attention', 'arguments' => $this->args()]]);
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('assistant_egress_denied', $denied->getMessage());
            self::assertSame($before, AssistantEgressEnrollment::query()->count());
            return;
        }
        self::assertSame(self::QUESTION, $manifest['input_context']['previous_user_question']);
        self::assertSame($before + 1, AssistantEgressEnrollment::query()->count());
        self::fail('Private parent question reached an owner reviewer without conversation access');
    }

    public function test_an_owner_reviewer_with_read_only_conversation_access_can_confirm_and_complete_a_child(): void
    {
        [$cohort, $parent] = $this->parent();
        $reviewer = $this->otherOwner();
        $this->addReviewerReadAccess($parent['conversation_id'], $reviewer);
        $conversations = app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class);
        self::assertNotSame((int) $cohort->actor_id, (int) $reviewer->id);
        self::assertNotNull($conversations->findAccessibleConversation($parent['conversation_id'], $reviewer, $cohort->organization_id, false));
        self::assertNull($conversations->findAccessibleConversation($parent['conversation_id'], $reviewer, $cohort->organization_id, true));
        self::assertNotNull($conversations->findAccessibleConversation($parent['conversation_id'], $this->fixture->owner, $cohort->organization_id, true));
        $payload = $this->childInput($parent);
        $manifest = $this->previewForReviewer($cohort, $payload, $reviewer);
        self::assertSame(self::QUESTION, $manifest['input_context']['previous_user_question']);
        self::assertSame((int) $reviewer->id, (int) AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->reviewed_by);
        $this->enrollments->confirm($reviewer, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        $calls = 0;
        $provider = $this->responses(function (array $input) use (&$calls, $manifest): array {
            self::assertSame(['system', 'user', 'user', 'user'], array_column(array_slice($input, 0, 4), 'role'));
            self::assertSame(self::QUESTION, json_decode($input[2]['content'], true, 512, JSON_THROW_ON_ERROR)['previous_user_question']);
            self::assertSame(self::REPLY, $input[3]['content']);
            if (++$calls === 1) {
                self::assertCount(4, $input);
                return $this->nativeCall('reviewer_read_only');
            }
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
            self::assertCount(1, $outputs);
            self::assertSame('call_reviewer_read_only', $outputs[0]['call_id']);
            self::assertSame($manifest['sources'][0]['projection'], json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
            return $this->answer(self::ANSWER);
        });
        $result = $this->ask($cohort, $payload, $provider);
        self::assertSame(2, $calls);
        self::assertSame(self::ANSWER, $result['message']['content']);
        self::assertFalse($result['message']['metadata']['needs_clarification']);
        self::assertNotEmpty($result['message']['metadata']['source_refs']);
    }

    public static function reviewerConfirmationChanges(): iterable
    {
        yield 'read revoked' => [true];
        yield 'another owner caller' => [false];
    }

    #[DataProvider('reviewerConfirmationChanges')]
    public function test_a_pending_child_cannot_be_confirmed_after_reviewer_read_revocation_or_by_another_owner(bool $revoke): void
    {
        [$cohort, $parent] = $this->parent();
        $reviewer = $this->otherOwner();
        $this->addReviewerReadAccess($parent['conversation_id'], $reviewer);
        $manifest = $this->previewForReviewer($cohort, $this->childInput($parent), $reviewer);
        if ($revoke) {
            $this->removeReviewerReadAccess($parent['conversation_id'], $reviewer);
            self::assertNull(app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)
                ->findAccessibleConversation($parent['conversation_id'], $reviewer, $cohort->organization_id, false));
        }
        try {
            $this->enrollments->confirm($revoke ? $reviewer : $this->fixture->owner, $cohort->organization_id,
                $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
            self::fail('A changed reviewer confirmation boundary was accepted');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('assistant_egress_denied', $denied->getMessage());
            $pending = AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id']);
            self::assertSame('pending_review', $pending->status);
            self::assertNull($pending->approved_at);
            self::assertSame((int) $reviewer->id, (int) $pending->reviewed_by);
        }
    }

    public static function reviewerWireBoundaries(): iterable
    {
        yield 'before first wire' => ['first'];
        yield 'before next wire' => ['next'];
        yield 'before publication' => ['publication'];
    }

    #[DataProvider('reviewerWireBoundaries')]
    public function test_reviewer_read_revocation_blocks_first_next_send_or_publication_and_keeps_occurred_usage(string $phase): void
    {
        [$cohort, $parent] = $this->parent();
        $reviewer = $this->otherOwner();
        $this->addReviewerReadAccess($parent['conversation_id'], $reviewer);
        $payload = $this->childInput($parent);
        $manifest = $this->previewForReviewer($cohort, $payload, $reviewer);
        $this->enrollments->confirm($reviewer, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        $before = AICreditProviderUsage::query()->where('organization_id', $cohort->organization_id)->count();
        if ($phase === 'first') { $this->removeReviewerReadAccess($parent['conversation_id'], $reviewer); }
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $phase, $parent, $reviewer): array {
            $calls++;
            if (($phase === 'next' && $calls === 1) || ($phase === 'publication' && $calls === 2)) {
                $this->removeReviewerReadAccess($parent['conversation_id'], $reviewer);
            }
            return $calls === 1 ? $this->nativeCall('reviewer_revocation') : $this->answer(self::ANSWER);
        });
        try {
            $this->ask($cohort, $payload, $provider);
            self::fail('Revoked reviewer read access reached an unauthorized send or publication');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('assistant_egress_denied', $denied->getMessage());
            self::assertSame(match ($phase) { 'first' => 0, 'next' => 1, default => 2 }, $calls);
            self::assertSame($before + $calls, AICreditProviderUsage::query()->where('organization_id', $cohort->organization_id)->count());
            self::assertSame(1, Message::query()->where('conversation_id', $parent['conversation_id'])->where('role', 'assistant')->count());
            self::assertNotNull(app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)
                ->findAccessibleConversation($parent['conversation_id'], $this->fixture->owner, $cohort->organization_id, true));
            $child = AssistantRequest::query()->where('request_id', $payload['request_id'])->first();
            if ($child !== null) { self::assertNull($child->clarification_receipt); }
        }
    }

    public static function invalidStoredReviewers(): iterable
    {
        yield 'missing server reviewer' => [true];
        yield 'inactive server reviewer' => [false];
    }

    #[DataProvider('invalidStoredReviewers')]
    public function test_missing_or_inactive_stored_reviewer_never_falls_back_to_actor_or_client_reviewer_id(bool $missing): void
    {
        [$cohort, $parent] = $this->parent();
        $reviewer = $this->otherOwner();
        $this->addReviewerReadAccess($parent['conversation_id'], $reviewer);
        $payload = $this->childInput($parent);
        $manifest = $this->previewForReviewer($cohort, $payload, $reviewer);
        $this->enrollments->confirm($reviewer, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        if ($missing) { AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['reviewed_by' => null]); }
        else { User::query()->whereKey($reviewer->id)->update(['is_active' => false]); }
        $payload['reviewer_id'] = $this->fixture->owner->id;
        $calls = 0;
        $provider = $this->responses(function () use (&$calls): never { $calls++; self::fail('An invalid stored reviewer reached provider transport'); });
        try {
            $this->ask($cohort, $payload, $provider);
            self::fail('An invalid server reviewer was replaced by another principal');
        } catch (AssistantEgressDenied $denied) {
            self::assertSame('assistant_egress_denied', $denied->getMessage());
            self::assertSame(0, $calls);
            self::assertSame(1, Message::query()->where('conversation_id', $parent['conversation_id'])->where('role', 'assistant')->count());
        }
    }

    public static function publicContextStages(): iterable
    {
        yield 'preview' => [false];
        yield 'confirm' => [true];
    }

    #[DataProvider('publicContextStages')]
    public function test_public_context_dependency_faults_reach_the_actual_controller_as_safe_500_without_approval(bool $confirm): void
    {
        [$cohort, $parent] = $this->parent();
        $private = 'PRIVATE_CURRENT_REPLY_ERROR_CANARY';
        $payload = $this->childInput($parent, $private);
        $context = app(OrdinaryAssistantClarificationContext::class)->preview($payload, $this->fixture->owner, $cohort, (int) $this->fixture->owner->id);
        self::assertSame(self::QUESTION, $context['preview']['previous_user_question']);
        $manifest = $confirm ? $this->preview($cohort, $payload) : null;
        $count = AssistantEgressEnrollment::query()->count();
        $this->withoutMiddleware();
        \Illuminate\Support\Facades\Exceptions::fake();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = new \Illuminate\Events\Dispatcher($this->app);
        $faults = 0;
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$faults, $parent, $private): void {
            if (str_contains($query->sql, 'from "ai_assistant_requests"') && in_array($parent['request_id'], $query->bindings, true)) {
                $faults++;
                self::privateContextFailure($private);
            }
        });
        $connection->setEventDispatcher($events);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            if ($confirm) {
                $response = $this->postJson('/api/v1/admin/ai-assistant/egress/enrollments/'.$manifest['enrollment_id'].'/confirm',
                    ['manifest_hash' => $manifest['manifest_hash'], 'declaration' => OrdinaryAssistantEnrollmentService::DECLARATION]);
            } else {
                $input = $payload;
                unset($input['request_id'], $input['quote_id']);
                $response = $this->postJson('/api/v1/admin/ai-assistant/egress/enrollments/preview', ['cohort_id' => $cohort->id,
                    'quote_id' => $payload['quote_id'], 'input' => $input,
                    'sources' => [['tool' => 'get_project_attention', 'arguments' => $this->args()]]]);
            }
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
            if ($original === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($original); }
        }
        self::assertSame(1, $faults);
        $response->assertStatus(500);
        self::assertStringNotContainsString($private, $response->getContent());
        \Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
        $failure = \Illuminate\Support\Facades\Exceptions::reported()[0];
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('assistant_provider_failed', $failure->getMessage());
        self::assertNull($failure->getPrevious());
        self::assertStringNotContainsString($private, (string) $failure);
        self::assertStringNotContainsString($private, $failure->getTraceAsString());
        self::assertStringNotContainsString($private, json_encode($failure->getTrace(), JSON_THROW_ON_ERROR));
        self::assertSame([], array_filter($failure->getTrace(), static fn (array $frame): bool => array_key_exists('args', $frame)));
        self::assertSame($count, AssistantEgressEnrollment::query()->count());
        if ($confirm) {
            $pending = AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id']);
            self::assertSame('pending_review', $pending->status);
            self::assertNull($pending->approved_at);
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    #[DataProvider('publicContextStages')]
    public function test_public_context_expected_denial_keeps_identity_and_actual_controller_403(bool $confirm): void
    {
        [$cohort, $parent] = $this->parent();
        $payload = $this->childInput($parent);
        $manifest = $confirm ? $this->preview($cohort, $payload) : null;
        $count = AssistantEgressEnrollment::query()->count();
        $denied = new AssistantEgressDenied('access_denied');
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = new \Illuminate\Events\Dispatcher($this->app);
        $faults = 0;
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$faults, $parent, $denied): void {
            if (str_contains($query->sql, 'from "ai_assistant_requests"') && in_array($parent['request_id'], $query->bindings, true)) {
                $faults++;
                throw $denied;
            }
        });
        $connection->setEventDispatcher($events);
        try {
            try {
                $context = app(OrdinaryAssistantClarificationContext::class);
                if ($confirm) { $context->refresh(AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->input_context, $this->fixture->owner, $cohort, (int) $this->fixture->owner->id); }
                else { $context->preview($payload, $this->fixture->owner, $cohort, (int) $this->fixture->owner->id); }
                self::fail('Expected context denial was swallowed');
            } catch (AssistantEgressDenied $actual) {
                self::assertSame($denied, $actual);
                self::assertSame('access_denied', $actual->reason);
            }
            $this->withoutMiddleware();
            \Illuminate\Support\Facades\Exceptions::fake();
            if ($confirm) {
                $response = $this->postJson('/api/v1/admin/ai-assistant/egress/enrollments/'.$manifest['enrollment_id'].'/confirm',
                    ['manifest_hash' => $manifest['manifest_hash'], 'declaration' => OrdinaryAssistantEnrollmentService::DECLARATION]);
            } else {
                $input = $payload;
                unset($input['request_id'], $input['quote_id']);
                $response = $this->postJson('/api/v1/admin/ai-assistant/egress/enrollments/preview', ['cohort_id' => $cohort->id,
                    'quote_id' => $payload['quote_id'], 'input' => $input,
                    'sources' => [['tool' => 'get_project_attention', 'arguments' => $this->args()]]]);
            }
        } finally {
            if ($original === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($original); }
        }
        self::assertSame(2, $faults);
        $response->assertStatus(403);
        \Illuminate\Support\Facades\Exceptions::assertNothingReported();
        self::assertSame($count, AssistantEgressEnrollment::query()->count());
        if ($confirm) {
            $pending = AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id']);
            self::assertSame('pending_review', $pending->status);
            self::assertNull($pending->approved_at);
        }
    }

    private static function privateContextFailure(string $private): never
    {
        throw new \RuntimeException($private, 0, new \RuntimeException($private));
    }

    private function addReviewerReadAccess(int $conversationId, User $reviewer): void
    {
        \App\BusinessModules\Features\AIAssistant\Models\ConversationParticipant::query()->create(['conversation_id' => $conversationId,
            'user_id' => $reviewer->id, 'role' => 'viewer', 'added_by_user_id' => $this->fixture->owner->id]);
    }

    private function removeReviewerReadAccess(int $conversationId, User $reviewer): void
    {
        $deleted = \App\BusinessModules\Features\AIAssistant\Models\ConversationParticipant::query()->where('conversation_id', $conversationId)
            ->where('user_id', $reviewer->id)->delete();
        self::assertSame(1, $deleted);
    }

    private function previewForReviewer(AssistantEgressCohort $cohort, array $payload, User $reviewer): array
    {
        return $this->enrollments->preview($reviewer, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => 'get_project_attention', 'arguments' => $this->args()]]);
    }
    private function otherOwner(): User
    {
        $reviewer = $this->fixture->administrator->fresh();
        $reviewer->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_owner' => true, 'project_access_mode' => 'all_projects']);
        UserRoleAssignment::query()->where('user_id', $reviewer->id)->update(['role_slug' => 'organization_owner']);
        $this->project->users()->syncWithoutDetaching([$reviewer->id => ['role' => 'member', 'is_active' => true,
            'assigned_by_user_id' => $this->fixture->owner->id, 'assigned_at' => now()]]);
        return $reviewer;
    }
    private function makeViewer(int $conversationId): void
    {
        Conversation::query()->whereKey($conversationId)->update(['user_id' => $this->operator->id]);
        \App\BusinessModules\Features\AIAssistant\Models\ConversationParticipant::query()->where('conversation_id', $conversationId)
            ->where('user_id', $this->fixture->owner->id)->sole()->update(['role' => 'viewer', 'added_by_user_id' => $this->operator->id]);
    }

    private function parent(bool $read = false, string $missing = 'goal', ?string $question = null, ?string $raw = null): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention']);
        $payload = $this->quote(['request_id' => (string) Str::uuid(), 'message' => $question ?? self::QUESTION,
            'conversation_id' => null, 'profile' => 'short', 'allow_actions' => false]);
        $manifest = $this->preview($cohort, $payload);
        $this->confirm($cohort, $manifest);
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $read, $missing, $raw): array {
            if ($calls === 0) { $this->parentEstimated = $options['estimated_input_tokens']; }
            if (++$calls === 1 && $read) { return $this->nativeCall('parent'); }
            return $this->answer($raw ?? json_encode(['kind' => 'clarification', 'missing' => $missing], JSON_THROW_ON_ERROR));
        });
        $result = $this->ask($cohort, $payload, $provider);
        self::assertSame($read ? 2 : 1, $calls);
        if ($raw === null) { self::assertSame(trans_message('ai_assistant.clarification_'.$missing), $result['message']['content']); }
        return [$cohort, $result, $manifest, $payload];
    }

    private function childInput(array $parent, string $reply = self::REPLY): array
    {
        return $this->quote(['request_id' => (string) Str::uuid(), 'message' => $reply, 'conversation_id' => $parent['conversation_id'],
            'profile' => 'short', 'allow_actions' => false,
            'context' => [OrdinaryAssistantClarificationContext::KEY => $parent['message']['metadata'][OrdinaryAssistantClarificationContext::KEY]]]);
    }

    private function quote(array $payload): array
    {
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        return $payload;
    }

    private function preview(AssistantEgressCohort $cohort, array $payload): array
    {
        return $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => 'get_project_attention', 'arguments' => $this->args()]]);
    }

    private function confirm(AssistantEgressCohort $cohort, array $manifest): void
    {
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
    }

    private function args(): array
    {
        return ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3];
    }

    private function ask(AssistantEgressCohort $cohort, array $payload, ContinuationResponsesProvider $provider): array
    {
        return AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, $payload['conversation_id'], $payload);
    }

    private function responses(callable $callback): ContinuationResponsesProvider
    {
        $provider = $this->createMock(ContinuationResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);
        return $provider;
    }

    private function nativeCall(string $id): array
    {
        $call = ['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => 'call_'.$id, 'status' => 'completed',
            'name' => 'get_project_attention', 'arguments' => json_encode($this->args(), JSON_THROW_ON_ERROR)];
        return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }

    private function answer(string $text): array
    {
        return ['content' => $text, 'output' => [], 'function_calls' => [], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface ContinuationResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
