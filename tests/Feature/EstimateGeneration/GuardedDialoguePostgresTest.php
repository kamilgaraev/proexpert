<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\CanonicalEstimateCommandProposalResolver;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpreter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\TimewebEstimateCommandInterpreter;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class GuardedDialoguePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private int $calls = 0;

    private ?\Closure $afterWire = null;

    public function test_real_http_adapter_sends_luna_json_and_preserves_reported_token_usage(): void
    {
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'fixture', 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"kind":"explain","explanation":"Нужны размеры","evidence_ids":[]}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 50, 'total_tokens' => 250,
                'prompt_tokens_details' => ['cached_tokens' => 30], 'completion_tokens_details' => ['reasoning_tokens' => 10]],
        ], JSON_THROW_ON_ERROR))]));
        $stack->push(Middleware::history($history));
        [$session, $actor, $interpreter, $context] = $this->fixture(new Client(['handler' => $stack]));
        self::assertSame('explain', $interpreter->interpretWithAttempt($session, $actor, 'Объясни', $context, 'request:http', static fn () => null)->kind());
        self::assertCount(1, $history);
        $payload = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('openai/gpt-6-luna', $payload['model']);
        self::assertSame(2048, $payload['max_completion_tokens']);
        self::assertArrayNotHasKey('estimate_generation_trusted_actor', $payload);
        $usage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->sole();
        self::assertSame(30, (int) $usage->cached_input_tokens);
        self::assertSame(10, (int) $usage->reasoning_tokens);
        self::assertSame('0.00607500', $usage->cost_amount);
    }

    public function test_late_response_after_cancellation_is_recorded_but_cannot_publish_or_resume_the_session(): void
    {
        [$session, $actor, $interpreter] = $this->fixture();
        $handler = $this->handler($session, $actor, $interpreter);
        $this->afterWire = static fn () => $session->update(['status' => 'cancelled', 'state_version' => 1]);
        try {
            $handler->handle($session, $actor, 'Объясни', 'request:cancel');
            self::fail('Late result was published.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure) {
            self::assertSame('cancelled', $session->fresh()->status->value);
            self::assertSame('response_received', DB::table('estimate_interpretation_attempts')->where('session_id', $session->id)->sole()->state);
            self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            self::assertSame(1, $this->calls);
        }
        try {
            $handler->handle($session->fresh(), $actor, 'Объясни', 'request:cancel');
        } catch (\Throwable) {
        }
        self::assertSame(1, $this->calls);
    }

    public function test_revoked_permission_after_reply_cannot_publish_a_paid_result(): void
    {
        [$session, $actor, $interpreter] = $this->fixture();
        $handler = $this->handler($session, $actor, $interpreter);
        $this->afterWire = static fn () => $actor->update(['is_active' => false]);
        try {
            $handler->handle($session, $actor, 'Объясни', 'request:late-rights');
            self::fail('Revoked actor published a result.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure) {
            self::assertSame('response_received', DB::table('estimate_interpretation_attempts')->where('session_id', $session->id)->sole()->state);
            self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            self::assertSame(1, $this->calls);
        }
    }

    public function test_unknown_dispatched_response_is_not_automatically_sent_again(): void
    {
        [$session, $actor, $interpreter] = $this->fixture();
        $handler = $this->handler($session, $actor, $interpreter);
        $this->afterWire = static fn () => throw new \RuntimeException('fixture_timeout_after_dispatch');
        for ($request = 0; $request < 2; $request++) {
            try {
                $handler->handle($session, $actor, 'Объясни', 'request:timeout');
                self::fail('Unknown response was accepted.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure) {
                self::assertSame(1, $this->calls);
            }
        }
        self::assertSame('ambiguous', DB::table('estimate_interpretation_attempts')->where('session_id', $session->id)->sole()->state);
        $usage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->sole();
        self::assertSame('unavailable', $usage->usage_status);
        self::assertNull($usage->cost_amount);
    }

    public function test_dialogue_uses_luna_and_review_actor_with_real_usage_and_atomic_pre_wire_callback(): void
    {
        [$session, $actor, $interpreter, $context] = $this->fixture();
        $marks = 0;
        $result = $interpreter->interpretWithAttempt($session, $actor, 'Объясни исходные данные', $context, 'request:1', function () use (&$marks, $session): void {
            self::assertGreaterThan(0, DB::transactionLevel());
            self::assertSame(0, DB::table('estimate_generation_vision_physical_attempts')->where('session_id', $session->id)->where('state', 'wire_started')->count());
            $marks++;
        });
        self::assertSame('explain', $result->kind());
        self::assertSame(1, $this->calls);
        self::assertSame(1, $marks);
        $usage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->sole();
        self::assertSame('estimate_dialogue', $usage->stage);
        self::assertSame('openai/gpt-6-luna', $usage->requested_model);
        self::assertSame(200, (int) $usage->input_tokens);
        self::assertSame(50, (int) $usage->output_tokens);
        self::assertSame('0.00607500', $usage->cost_amount);
        $recovered = $interpreter->recoverReceivedResponse($session, $actor, 'Объясни исходные данные', $context, 'request:1');
        self::assertSame($result->payload, $recovered?->payload);
        self::assertSame(1, $this->calls);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertNull($interpreter->recoverReceivedResponse($session, $actor, 'Другой запрос', $context, 'request:unknown'));
        self::assertSame(1, $this->calls);
    }

    public function test_refused_callback_rolls_back_physical_reservation_without_sending_or_recording_a_paid_call(): void
    {
        [$session, $actor, $interpreter, $context] = $this->fixture();
        try {
            $interpreter->interpretWithAttempt($session, $actor, 'Объясни', $context, 'request:refused', fn () => throw new \RuntimeException('Logical attempt ownership lost'));
            self::fail('Refused callback was sent.');
        } catch (AiWireNotStarted) {
            self::assertSame(0, $this->calls);
            self::assertSame(0, DB::table('estimate_generation_vision_physical_attempts')->where('session_id', $session->id)->count());
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        }
    }

    public function test_revoked_actor_and_stale_snapshot_cannot_send_dialogue(): void
    {
        [$session, $actor, $interpreter, $context] = $this->fixture();
        $actor->update(['is_active' => false]);
        try {
            $interpreter->interpretWithAttempt($session, $actor, 'Объясни', $context, 'request:revoked', fn () => self::fail('Unauthorized callback'));
            self::fail('Revoked actor was accepted.');
        } catch (AiWireNotStarted) {
            self::assertSame(0, $this->calls);
        }
        $actor->update(['is_active' => true]);
        $session->update(['state_version' => 1]);
        try {
            $interpreter->interpretWithAttempt($session, $actor, 'Объясни', $context, 'request:stale', fn () => self::fail('Stale callback'));
            self::fail('Stale snapshot was accepted.');
        } catch (AiWireNotStarted) {
            self::assertSame(0, $this->calls);
        }
    }

    public function test_lost_logical_receipt_is_recovered_from_durable_response_without_another_send(): void
    {
        [$session, $actor, $interpreter] = $this->fixture();
        $session->update(['input_payload' => ['area' => '20']]);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter::class)->write($session, $actor);
        $this->app->instance(EstimateCommandInterpreter::class, $interpreter);
        $handler = app(InterpretEstimateCommand::class);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_test_dialogue_receipt_failure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.state = 'response_received' THEN RAISE EXCEPTION 'fixture_receipt_write_failed'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_test_dialogue_receipt_failure_trg BEFORE UPDATE ON estimate_interpretation_attempts FOR EACH ROW EXECUTE FUNCTION eg_test_dialogue_receipt_failure();
SQL);
        try {
            $handler->handle($session, $actor, 'Объясни', 'request:receipt');
            self::fail('Receipt failure was hidden.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure) {
            self::assertSame(1, $this->calls);
        }
        DB::unprepared('DROP TRIGGER eg_test_dialogue_receipt_failure_trg ON estimate_interpretation_attempts; DROP FUNCTION eg_test_dialogue_receipt_failure();');
        $result = $handler->handle($session, $actor, 'Объясни', 'request:receipt');
        self::assertSame('explanation', $result['kind']);
        self::assertSame(1, $this->calls);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame('completed', DB::table('estimate_interpretation_attempts')->where('session_id', $session->id)->sole()->state);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($handler->handle($session, $actor, 'Объясни', 'request:receipt')));
        self::assertSame(1, $this->calls);
    }

    public function test_shared_wallet_dialogue_requires_the_exact_quote_and_replays_one_settled_questions_result(): void
    {
        [$session, $actor, $interpreter] = $this->fixture(realPrices: true);
        config(['estimate-generation.credits.enabled' => true, 'ai-assistant-credits.enforce' => false]);
        $handler = $this->handler($session, $actor, $interpreter);
        $id = (string) \Illuminate\Support\Str::uuid();
        try {
            $handler->handle($session, $actor, 'Объясни', $id);
            self::fail('Dialogue sent without a quote.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, $this->calls);
        }
        $credits = app(\App\Services\Credits\AICreditService::class);
        $organization = Organization::query()->findOrFail($session->organization_id);
        $credits->grant($organization, 1000000, 'purchase', null, 'fixture:shared-dialogue');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'dialogue', ['command' => 'Объясни', 'idempotency_key' => $id]);
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0];
        try {
            $handler->handle($session, $actor, 'Измени площадь', $id, $approval);
            self::fail('Quote accepted a changed command.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, $this->calls);
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
        }
        $result = $handler->handle($session, $actor, 'Объясни', $id, $approval);
        self::assertSame('explanation', $result['kind']);
        self::assertSame(1, $this->calls);
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        self::assertSame('settled', $operation->status);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
        self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count());
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('ai_credit_reservation_id', $operation->reservation_id)->count());
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($handler->handle($session, $actor, 'Объясни', $id, $approval)));
        self::assertSame(1, $this->calls);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
    }

    public function test_shared_dialogue_late_cancel_revocation_and_unknown_response_release_the_reserve_without_another_send(): void
    {
        foreach (['cancel', 'revoked', 'unknown'] as $event) {
            $this->calls = 0;
            $this->afterWire = null;
            [$session, $actor, $interpreter] = $this->fixture(realPrices: true);
            config(['estimate-generation.credits.enabled' => true, 'ai-assistant-credits.enforce' => false]);
            $handler = $this->handler($session, $actor, $interpreter);
            $organization = Organization::query()->findOrFail($session->organization_id);
            $credits = app(\App\Services\Credits\AICreditService::class);
            $credits->grant($organization, 1000000, 'purchase', null, 'fixture:late-dialogue:'.$event);
            $id = (string) \Illuminate\Support\Str::uuid();
            $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'dialogue', ['command' => 'Объясни', 'idempotency_key' => $id]);
            $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0];
            $this->afterWire = match ($event) {
                'cancel' => static fn () => $session->update(['status' => 'cancelled', 'state_version' => 1]),
                'revoked' => static fn () => $actor->update(['is_active' => false]),
                'unknown' => static fn () => throw new \RuntimeException('fixture_timeout_after_dispatch'),
            };
            try {
                $handler->handle($session, $actor, 'Объясни', $id, $approval);
                self::fail('A late/unknown dialogue published its result: '.$event);
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure) {
                $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
                self::assertSame('failed', $operation->status, $event);
                self::assertSame(0, (int) $operation->consumed_units_minor, $event);
                self::assertSame(1000000, $credits->balance($organization)['available_minor'], $event);
                self::assertSame(1, $this->calls, $event);
                self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count(), $event);
                self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count(), $event);
            }
            try {
                $handler->handle($session->fresh(), $actor, 'Объясни', $id, $approval);
            } catch (\Throwable) {
            }
            self::assertSame(1, $this->calls, $event);
        }
    }

    private function handler(EstimateGenerationSession $session, User $actor, TimewebEstimateCommandInterpreter $interpreter): InterpretEstimateCommand
    {
        $session->update(['input_payload' => ['area' => '20']]);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter::class)->write($session, $actor);
        $this->app->instance(EstimateCommandInterpreter::class, $interpreter);

        return app(InterpretEstimateCommand::class);
    }

    private function fixture(?\GuzzleHttp\ClientInterface $httpClient = null, bool $realPrices = false): array
    {
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $permissions = $this->createMock(AuthorizationService::class);
        $permissions->method('canCurrent')->willReturnCallback(static fn ($user, $permission): bool => $permission === 'estimate_generation.review');
        $authorization = new EstimateGenerationActionAuthorizer($permissions);
        $this->app->instance(EstimateGenerationActionAuthorization::class, $authorization);
        $responses = new DurableAiPhysicalResponseStore(DB::connection());
        $wire = new TimewebRerankWireClient(new TimewebChatCompletionPayloadFactory, new SessionAiCostGuard(DB::connection(), $authorization), $responses,
            $httpClient === null ? function (array $payload): array {
                $this->calls++;
                self::assertSame('openai/gpt-6-luna', $payload['model']);
                self::assertArrayNotHasKey('estimate_generation_trusted_actor', $payload);
                ($this->afterWire ?? static fn () => null)();

                return ['content' => '{"kind":"explain","explanation":"Оценка требует исходных размеров","evidence_ids":[]}',
                    'model' => $payload['model'], 'usage_available' => true, 'input_tokens' => 200, 'output_tokens' => 50,
                    'cached_input_tokens' => 30, 'reasoning_tokens' => 10, 'finish_reason' => 'stop'];
            } : null, httpClient: $httpClient);
        $prices = $this->createMock(AiPriceSnapshotResolver::class);
        $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray(['currency' => 'RUB', 'source' => 'fixture',
            'version' => 'dialogue-v1', 'effective_at' => '2026-10-10T00:00:00+00:00', 'input_per_million' => '13.5',
            'cached_input_per_million' => '13.5', 'output_per_million' => '67.5', 'reasoning_per_million' => '67.5', 'reasoning_mode' => 'included_in_output']));
        $interpreter = new TimewebEstimateCommandInterpreter($wire, app(AiUsageStore::class), $realPrices ? app(AiPriceSnapshotResolver::class) : $prices, $responses, new CanonicalEstimateCommandProposalResolver, $authorization);
        $context = ['scope' => ['organization_id' => (int) $organization->id, 'project_id' => (int) $project->id, 'session_id' => (int) $session->id],
            'snapshot' => ['state_version' => 0], 'facts' => [], 'allowed_references' => [], 'evidence' => []];

        return [$session, $actor, $interpreter, $context];
    }
}
