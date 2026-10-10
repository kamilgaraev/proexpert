<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\SystemAdmin;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EstimateGeneration\EstimateGenerationApplicationTestCase;

final class TextAiWireAdmissionPostgresTest extends EstimateGenerationApplicationTestCase
{
    private string $schema;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertStringEndsWith('_testing', DB::connection()->getDatabaseName());
        $this->schema = 'most_text_wire_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA "'.$this->schema.'"');
        DB::unprepared('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(<<<'SQL'
            CREATE TABLE organizations (id bigint PRIMARY KEY, deleted_at timestamp NULL);
            CREATE TABLE projects (id bigint PRIMARY KEY, organization_id bigint NOT NULL DEFAULT 10,
                is_archived boolean NOT NULL DEFAULT false, deleted_at timestamp NULL);
            CREATE TABLE project_organization (project_id bigint NOT NULL, organization_id bigint NOT NULL, is_active boolean NOT NULL DEFAULT true);
            CREATE TABLE project_user (project_id bigint NOT NULL, user_id bigint NOT NULL, is_active boolean NOT NULL DEFAULT true);
            CREATE TABLE users (id bigint PRIMARY KEY, current_organization_id bigint NOT NULL,
                is_active boolean NOT NULL DEFAULT true, deleted_at timestamp NULL);
            CREATE TABLE system_admins (id bigint PRIMARY KEY, is_active boolean NOT NULL DEFAULT true);
            CREATE TABLE organization_user (organization_id bigint NOT NULL, user_id bigint NOT NULL,
                is_active boolean NOT NULL DEFAULT true, is_owner boolean NOT NULL DEFAULT false,
                project_access_mode varchar(32) NOT NULL DEFAULT 'all_projects');
            CREATE TABLE estimate_generation_sessions (
                id bigint PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint NOT NULL,
                user_id bigint NOT NULL DEFAULT 40,
                status varchar(32) NOT NULL, state_version integer NOT NULL DEFAULT 1,
                input_payload jsonb NOT NULL DEFAULT '{}', analysis_payload jsonb NOT NULL DEFAULT '{}'
            );
            CREATE TABLE estimate_generation_documents (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_document_pages (id bigint PRIMARY KEY);
            SQL);
        foreach ([
            '2026_08_10_000300_create_vision_physical_attempts.php',
            '2026_08_10_000400_add_recovery_lease_to_vision_physical_attempts.php',
            '2026_08_15_000100_separate_vision_logical_request_from_processing_lineage.php',
            '2026_08_18_000200_add_vision_physical_cost_reservations.php',
            '2026_08_14_000100_create_estimate_generation_ai_role_runs.php',
        ] as $migration) {
            (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/'.$migration))->up();
        }
        Schema::create('estimate_generation_ai_usage', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_id')->unique();
            $table->uuid('correlation_id');
            $table->string('immutable_fingerprint', 80);
            foreach (['organization_id', 'project_id', 'session_id'] as $key) {
                $table->unsignedBigInteger($key);
            }
            foreach (['document_id', 'page_id', 'unit_id'] as $key) {
                $table->unsignedBigInteger($key)->nullable();
            }
            foreach (['stage', 'operation', 'provider', 'requested_model', 'status', 'usage_status', 'pricing_status'] as $key) {
                $table->string($key, 160);
            }
            $table->string('reported_model', 160)->nullable();
            $table->unsignedInteger('attempt_ordinal');
            $table->unsignedSmallInteger('http_code')->nullable();
            foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'image_count', 'page_count', 'duration_ms'] as $key) {
                $table->unsignedInteger($key);
            }
            $table->string('image_detail')->nullable();
            $table->jsonb('price_snapshot');
            $table->jsonb('request_context');
            $table->decimal('cost_amount', 18, 8)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestampTz('created_at');
        });
        DB::table('organizations')->insert(['id' => 10]);
        DB::table('projects')->insert(['id' => 20]);
        DB::table('users')->insert(['id' => 40, 'current_organization_id' => 10]);
        DB::table('organization_user')->insert(['organization_id' => 10, 'user_id' => 40, 'is_active' => true]);
        DB::table('estimate_generation_sessions')->insert([
            'id' => 30, 'organization_id' => 10, 'project_id' => 20, 'status' => 'generating',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^most_text_wire_[a-f0-9]{16}$/D', $this->schema) === 1) {
            DB::unprepared('SET search_path TO public');
            DB::unprepared('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    public function test_first_composer_call_is_reserved_sent_once_published_and_replayed(): void
    {
        $composer = $this->composer();
        $first = $composer->run($this->input());
        self::assertNotEmpty($first);
        self::assertSame($first, $composer->run($this->input()));
        self::assertSame(1, $this->calls);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->count());
        $attempt = DB::table('estimate_generation_vision_physical_attempts')->sole();
        self::assertSame('completed', $attempt->state);
        self::assertNotNull($attempt->wire_started_at);
        self::assertGreaterThan(0, (float) $attempt->cost_reservation_amount);
        self::assertSame('completed', DB::table('estimate_generation_ai_role_runs')->sole()->status);
    }

    public function test_real_sdk_http_transport_is_scoped_reserved_and_replayed_without_another_http_request(): void
    {
        $history = [];
        $composer = $this->composer($this->httpWire($history, [[
            'index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"work_intents":[]}'],
            'finish_reason' => 'stop', 'logprobs' => null,
        ]]));
        $result = $composer->run($this->input());
        self::assertNotEmpty($result);
        self::assertSame($result, $composer->run($this->input()));
        self::assertCount(1, $history);
        $request = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('openai/gpt-6-luna', $request['model']);
        self::assertSame(2048, $request['max_completion_tokens']);
        self::assertArrayNotHasKey('estimate_generation_scope', $request);
        self::assertArrayNotHasKey('estimate_generation_attempt', $request);
        self::assertSame('0.00472500', DB::table('estimate_generation_ai_usage')->sole()->cost_amount);
        self::assertSame(20, DB::table('estimate_generation_ai_usage')->sole()->cached_input_tokens);
        self::assertSame(10, DB::table('estimate_generation_ai_usage')->sole()->reasoning_tokens);
    }

    public function test_empty_sdk_choices_preserve_supplier_usage_and_recovery_does_not_resend(): void
    {
        $history = [];
        $composer = $this->composer($this->httpWire($history, []));
        foreach (range(1, 2) as $attempt) {
            try {
                $composer->run($this->input());
                self::fail('Empty choices are not an estimate result.');
            } catch (RerankWireException $exception) {
                self::assertSame('malformed_response', $exception->attemptStatus);
            }
        }
        self::assertCount(1, $history);
        self::assertSame('measured', DB::table('estimate_generation_ai_usage')->sole()->usage_status);
        self::assertSame('0.00472500', DB::table('estimate_generation_ai_usage')->sole()->cost_amount);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_pre_wire_denial_keeps_safe_retry_and_creates_no_supplier_usage(): void
    {
        config(['estimate-generation.generation.session_cost_limit_rub' => '0.00000001']);
        try {
            $this->composer()->run($this->input());
            self::fail('Expected the next-call reservation to exceed the approved ceiling.');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('session_cost_limit_reached', $exception->reason);
        }
        self::assertSame(0, $this->calls);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame('pre_wire', DB::table('estimate_generation_vision_physical_attempts')->sole()->state);
        self::assertSame('failed', DB::table('estimate_generation_ai_role_runs')->sole()->status);
        config(['estimate-generation.generation.session_cost_limit_rub' => '900']);
        self::assertNotEmpty($this->composer()->run($this->input()));
        self::assertSame(1, $this->calls);
    }

    public function test_database_failure_before_wire_never_creates_a_supplier_receipt_and_can_be_retried(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_wire_admission() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.state = 'wire_started' THEN
                    RAISE EXCEPTION 'injected admission failure';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER wire_admission_fault BEFORE UPDATE OF state
            ON estimate_generation_vision_physical_attempts FOR EACH ROW EXECUTE FUNCTION fail_wire_admission();
            SQL);
        try {
            $this->composer()->run($this->input());
            self::fail('Admission failure must stop before transport.');
        } catch (AiWireNotStarted $exception) {
            self::assertSame('text_ai_wire_admission_failed', $exception->getMessage());
        }
        self::assertSame(0, $this->calls);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame('pre_wire', DB::table('estimate_generation_vision_physical_attempts')->sole()->state);
        self::assertSame('failed', DB::table('estimate_generation_ai_role_runs')->sole()->status);
        DB::unprepared('DROP TRIGGER wire_admission_fault ON estimate_generation_vision_physical_attempts');
        self::assertNotEmpty($this->composer()->run($this->input()));
        self::assertSame(1, $this->calls);
    }

    public function test_known_unsent_admission_is_released_only_for_the_current_role_owner(): void
    {
        $owner = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $attemptId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $runs = new EloquentAiRoleRunRepository(DB::connection(), 180);
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput(
            10, 20, 30, null, null, 'estimate_session', '30', str_repeat('a', 64),
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole::EstimateComposer,
            'openai/gpt-6-luna', RunEstimateComposer::PROMPT_CONTRACT, $this->input()->fingerprint(),
        );
        $claim = $runs->claim($input, $owner);
        $runs->startPhysicalAttempt($claim->runId, $owner, $attemptId);
        DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attemptId)->update([
            'state' => 'wire_started', 'wire_started_at' => now(), 'cost_reservation_amount' => '0.1', 'cost_reservation_currency' => 'RUB',
        ]);
        $runs->fail($claim->runId, $owner, new AiRoleRunFailure('admission_failed_before_send', physicalAttemptId: $attemptId, wireNotStarted: true));
        $physical = DB::table('estimate_generation_vision_physical_attempts')->sole();
        self::assertSame('pre_wire', $physical->state);
        self::assertNull($physical->wire_started_at);
        self::assertNull($physical->cost_reservation_amount);
        self::assertSame('failed', DB::table('estimate_generation_ai_role_runs')->sole()->status);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        $secondOwner = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $claim = $runs->claim($input, $secondOwner);
        $runs->startPhysicalAttempt($claim->runId, $secondOwner, $attemptId);
        DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attemptId)->update([
            'state' => 'wire_started', 'owner_token' => $owner, 'wire_started_at' => now(),
            'cost_reservation_amount' => '0.1', 'cost_reservation_currency' => 'RUB',
        ]);
        $runs->fail($claim->runId, $secondOwner, new AiRoleRunFailure('other_owner_outcome_unknown', physicalAttemptId: $attemptId, wireNotStarted: true));
        self::assertSame('wire_started', DB::table('estimate_generation_vision_physical_attempts')->sole()->state);
        self::assertSame('ambiguous', DB::table('estimate_generation_ai_role_runs')->sole()->status);
    }

    public function test_completed_raw_response_can_be_parsed_after_a_transient_publication_failure_without_another_send(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE parsed_store_fault;
            CREATE FUNCTION fail_first_parsed_store() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.response_payload ? 'parsed_response' AND nextval('parsed_store_fault') = 1 THEN
                    RAISE EXCEPTION 'injected parsed publication failure';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER parsed_store_fault BEFORE UPDATE OF response_payload
            ON estimate_generation_vision_physical_attempts FOR EACH ROW EXECUTE FUNCTION fail_first_parsed_store();
            SQL);
        try {
            $this->composer()->run($this->input());
            self::fail('Expected the injected publication failure.');
        } catch (QueryException) {
            self::assertSame(1, $this->calls);
            self::assertSame('completed', DB::table('estimate_generation_vision_physical_attempts')->sole()->state);
            self::assertSame('failed', DB::table('estimate_generation_ai_role_runs')->sole()->status);
        }
        $usage = DB::table('estimate_generation_ai_usage')->sole();
        self::assertNotEmpty($this->composer()->run($this->input()));
        self::assertSame(1, $this->calls);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->count());
        self::assertEquals($usage, DB::table('estimate_generation_ai_usage')->sole());
        self::assertSame('completed', DB::table('estimate_generation_ai_role_runs')->sole()->status);
        self::assertSame('completed', DB::table('estimate_generation_vision_physical_attempts')->sole()->state);
    }

    public function test_failed_wire_response_storage_preserves_measured_supplier_cost_and_blocks_resending(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_raw_wire_store() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.response_payload IS NOT NULL AND OLD.response_payload IS NULL THEN
                    RAISE EXCEPTION 'injected raw response storage failure';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER raw_store_fault BEFORE UPDATE OF response_payload
            ON estimate_generation_vision_physical_attempts FOR EACH ROW EXECUTE FUNCTION fail_raw_wire_store();
            SQL);
        try {
            $this->composer()->run($this->input());
            self::fail('Expected raw response storage failure.');
        } catch (RerankWireException $exception) {
            self::assertSame(100, $exception->providerResponse['input_tokens']);
            self::assertSame(50, $exception->providerResponse['output_tokens']);
        }
        $usage = DB::table('estimate_generation_ai_usage')->sole();
        self::assertSame('measured', $usage->usage_status);
        self::assertSame('available', $usage->pricing_status);
        self::assertSame('0.00472500', $usage->cost_amount);
        self::assertSame('ambiguous', DB::table('estimate_generation_ai_role_runs')->sole()->status);
        try {
            $this->composer()->run($this->input());
            self::fail('Expected manual recovery instead of another paid send.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_composer_role_run_ambiguous', $exception->getMessage());
        }
        self::assertSame(1, $this->calls);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_cancelled_and_archived_sessions_never_send_a_new_text_request(): void
    {
        foreach (['cancelled', 'archived'] as $status) {
            DB::table('estimate_generation_sessions')->where('id', 30)->update(['status' => $status]);
            try {
                $this->composer()->run($this->input());
                self::fail('Expected terminal session admission to fail.');
            } catch (SessionAiCostLimitReached $exception) {
                self::assertSame('session_processing_stopped', $exception->reason);
            }
        }
        self::assertSame(0, $this->calls);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_unknown_existing_attempt_blocks_a_new_send(): void
    {
        DB::table('estimate_generation_vision_physical_attempts')->insert([
            'attempt_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'request_fingerprint' => str_repeat('f', 64), 'logical_request_fingerprint' => str_repeat('f', 64),
            'organization_id' => 10, 'project_id' => 20, 'session_id' => 30,
            'state' => 'ambiguous', 'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $this->composer()->run($this->input());
            self::fail('Expected unknown physical liability to block a new send.');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('session_cost_accounting_unavailable', $exception->reason);
        }
        self::assertSame(0, $this->calls);
    }

    public function test_stale_state_version_cannot_send_a_new_request(): void
    {
        DB::table('estimate_generation_sessions')->where('id', 30)->update(['state_version' => 2]);
        try {
            $this->composer()->run($this->input());
            self::fail('Expected stale operation state to prevent a new physical request.');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('session_processing_stopped', $exception->reason);
        }
        self::assertSame(0, $this->calls);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_actor_type_prevents_colliding_user_and_platform_admin_identifiers(): void
    {
        DB::table('system_admins')->insert(['id' => 40]);
        self::assertInstanceOf(User::class, EstimateGenerationExecutionActor::resolve([], 40));
        self::assertInstanceOf(SystemAdmin::class, EstimateGenerationExecutionActor::resolve([
            'generation_actor_type' => 'system_admin', 'generation_actor_id' => 40,
        ], 99));
        self::assertNull(EstimateGenerationExecutionActor::resolve([
            'generation_actor_type' => 'unknown', 'generation_actor_id' => 40,
        ], 40));
        DB::table('estimate_generation_sessions')->where('id', 30)->update([
            'input_payload' => json_encode(['generation_actor_type' => 'unknown', 'generation_actor_id' => 40], JSON_THROW_ON_ERROR),
        ]);
        try {
            $this->composer()->run($this->input());
            self::fail('Unknown actor types cannot fall back to a permitted user.');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('session_actor_not_authorized', $exception->reason);
        }
        self::assertSame(0, $this->calls);
    }

    public function test_current_actor_status_and_membership_are_checked_before_every_new_wire(): void
    {
        foreach (['inactive', 'deleted', 'switched_organization', 'membership_revoked', 'project_reassigned', 'project_deleted'] as $case) {
            DB::table('users')->where('id', 40)->update(['is_active' => true, 'deleted_at' => null, 'current_organization_id' => 10]);
            DB::table('organization_user')->where('user_id', 40)->update(['is_active' => true]);
            DB::table('projects')->where('id', 20)->update(['organization_id' => 10, 'deleted_at' => null]);
            match ($case) {
                'inactive' => DB::table('users')->where('id', 40)->update(['is_active' => false]),
                'deleted' => DB::table('users')->where('id', 40)->update(['deleted_at' => now()]),
                'switched_organization' => DB::table('users')->where('id', 40)->update(['current_organization_id' => 99]),
                'membership_revoked' => DB::table('organization_user')->where('user_id', 40)->update(['is_active' => false]),
                'project_reassigned' => DB::table('projects')->where('id', 20)->update(['organization_id' => 99]),
                'project_deleted' => DB::table('projects')->where('id', 20)->update(['deleted_at' => now()]),
            };
            try {
                $this->composer()->run($this->input());
                self::fail('Expected current actor admission to reject '.$case);
            } catch (SessionAiCostLimitReached $exception) {
                self::assertSame('session_actor_not_authorized', $exception->reason);
            }
        }
        self::assertSame(0, $this->calls);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_shared_project_access_requires_active_participation_and_current_user_assignment(): void
    {
        DB::table('projects')->where('id', 20)->update(['organization_id' => 99]);
        DB::table('project_organization')->insert(['project_id' => 20, 'organization_id' => 10]);
        DB::table('organization_user')->where('user_id', 40)->update(['project_access_mode' => 'assigned_projects']);
        DB::table('project_user')->insert(['project_id' => 20, 'user_id' => 40]);
        $permissions = $this->createMock(AuthorizationService::class);
        $permissions->method('canCurrent')->willReturn(true);
        $authorization = new EstimateGenerationActionAuthorizer($permissions);
        $actor = User::query()->findOrFail(40);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail(30);
        $authorization->authorize($actor, $session, 'estimate_generation.generate');
        foreach (['participation', 'assignment', 'archived'] as $revocation) {
            DB::table('project_organization')->update(['is_active' => $revocation !== 'participation']);
            DB::table('project_user')->update(['is_active' => $revocation !== 'assignment']);
            DB::table('projects')->update(['is_archived' => $revocation === 'archived']);
            try {
                $authorization->authorize($actor, $session, 'estimate_generation.generate');
                self::fail('Revoked project access must be rejected.');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                self::assertSame(0, $this->calls);
            }
        }
    }

    public function test_cached_positive_permission_does_not_authorize_a_revoked_permission(): void
    {
        $permissions = $this->createMock(AuthorizationService::class);
        $permissions->expects(self::never())->method('can')->willReturn(true);
        $permissions->expects(self::exactly(2))->method('canCurrent')->willReturnOnConsecutiveCalls(true, false);
        $authorization = new EstimateGenerationActionAuthorizer($permissions);
        $actor = User::query()->findOrFail(40);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->findOrFail(30);
        $authorization->authorize($actor, $session, 'estimate_generation.generate');
        $guard = new SessionAiCostGuard(DB::connection(), $authorization);
        try {
            $guard->authorize(10, 20, 30, new \App\BusinessModules\Addons\EstimateGeneration\Observability\TextAiWireAttempt(
                'cccccccc-cccc-4ccc-8ccc-cccccccccccc', str_repeat('c', 64),
                new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost('0.05', 'RUB', 'available'), 30, stateVersion: 1,
            ));
            self::fail('Expected revoked current permission to block a wire reservation.');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('session_actor_not_authorized', $exception->reason);
        }
        self::assertSame(0, DB::table('estimate_generation_vision_physical_attempts')->count());
    }

    public function test_rerank_positions_have_distinct_receipts_and_recovery_reuses_the_response(): void
    {
        $prices = $this->createMock(AiPriceSnapshotResolver::class);
        $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([
            'currency' => 'RUB', 'source' => 'fixture', 'version' => 'wire-test-v1',
            'effective_at' => '2026-10-10T00:00:00+00:00',
            'input_per_million' => '13.5', 'cached_input_per_million' => '13.5', 'output_per_million' => '67.5',
            'reasoning_per_million' => '67.5', 'reasoning_mode' => 'included_in_output',
        ]));
        $client = new AttemptAwareNormativeLlmClient(
            $this->wire(), new EloquentAiUsageStore(new AiCostCalculator, DB::connection()),
            ['openai/gpt-6-luna'], priceResolver: $prices,
        );
        $context = ['organization_id' => 10, 'project_id' => 20, 'session_id' => 30,
            'checkpoint_claim_token' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'input_version' => 'input-v1',
            'logical_attempt' => 1, 'work_item_key' => 'floor:1', 'candidate_set_hash' => str_repeat('a', 64)];
        $messages = [['role' => 'user', 'content' => 'Rank the supplied candidates.']];
        $first = $client->chat($messages, ['profile' => 'json', 'max_tokens' => 256], $context);
        $client->chat($messages, ['profile' => 'json', 'max_tokens' => 256],
            [...$context, 'work_item_key' => 'floor:2', 'candidate_set_hash' => str_repeat('b', 64)]);
        $replay = $client->chat($messages, ['profile' => 'json', 'max_tokens' => 256], [
            ...$context, 'checkpoint_claim_token' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'logical_attempt' => 2,
            'state_version' => 1,
        ]);
        self::assertSame($first['content'], $replay['content']);
        self::assertSame(2, $this->calls);
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->distinct()->count('attempt_id'));
        self::assertSame(2, DB::table('estimate_generation_vision_physical_attempts')->where('state', 'completed')->count());
    }

    private function composer(?TimewebRerankWireClient $wire = null): RunEstimateComposer
    {
        $price = AiPriceSnapshot::fromArray([
            'currency' => 'RUB', 'source' => 'fixture', 'version' => 'wire-test-v1',
            'effective_at' => '2026-10-10T00:00:00+00:00',
            'input_per_million' => '13.5', 'cached_input_per_million' => '13.5', 'output_per_million' => '67.5',
            'reasoning_mode' => 'included_in_output',
            'reasoning_per_million' => '67.5',
        ]);
        $prices = $this->createMock(AiPriceSnapshotResolver::class);
        $prices->method('resolve')->willReturn($price);
        $wire ??= $this->wire();

        return new RunEstimateComposer(new EloquentAiRoleRunRepository(DB::connection(), 180), new TimewebEstimateComposerModel(
            $wire, new EloquentAiUsageStore(new AiCostCalculator, DB::connection()), $prices,
            'openai/gpt-6-luna', 100000, 4096, 30, new DurableAiPhysicalResponseStore(DB::connection()),
        ), 'openai/gpt-6-luna');
    }

    private function wire(): TimewebRerankWireClient
    {
        $permissions = $this->createMock(AuthorizationService::class);
        $permissions->method('canCurrent')->willReturn(true);

        return new TimewebRerankWireClient(new TimewebChatCompletionPayloadFactory, new SessionAiCostGuard(DB::connection(), new EstimateGenerationActionAuthorizer($permissions)), new DurableAiPhysicalResponseStore(DB::connection()), function (array $payload): array {
            $this->calls++;
            self::assertSame('wire_started', DB::table('estimate_generation_vision_physical_attempts')->where('state', 'wire_started')->sole()->state);

            return [
                'content' => json_encode(['work_intents' => [[
                    'kind' => 'existing', 'candidate_id' => 'baseline:floor',
                    'work_key' => null, 'name' => null, 'derived_quantity_id' => null,
                    'source_fact_ids' => ['fact:floor'], 'technology_package_candidate' => null,
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                ]]], JSON_THROW_ON_ERROR),
                'model' => $payload['model'], 'usage_available' => true,
                'input_tokens' => 100, 'output_tokens' => 50, 'finish_reason' => 'stop',
            ];
        });

    }

    private function httpWire(array &$history, array $choices): TimewebRerankWireClient
    {
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $handler = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-fixture', 'object' => 'chat.completion', 'created' => 1,
            'model' => 'openai/gpt-6-luna', 'choices' => $choices,
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150,
                'prompt_tokens_details' => ['cached_tokens' => 20],
                'completion_tokens_details' => ['reasoning_tokens' => 10]],
        ], JSON_THROW_ON_ERROR))]));
        $handler->push(Middleware::history($history));
        $permissions = $this->createMock(AuthorizationService::class);
        $permissions->method('canCurrent')->willReturn(true);

        return new TimewebRerankWireClient(new TimewebChatCompletionPayloadFactory,
            new SessionAiCostGuard(DB::connection(), new EstimateGenerationActionAuthorizer($permissions)),
            new DurableAiPhysicalResponseStore(DB::connection()), httpClient: new Client(['handler' => $handler]));
    }

    private function input(): EstimateComposerInput
    {
        return new EstimateComposerInput(10, 20, 30, str_repeat('a', 64),
            [['id' => 'fact:floor', 'status' => 'confirmed']],
            [['id' => 'quantity:floor', 'value' => '10', 'unit' => 'm2']], [], [[
                'candidate_id' => 'baseline:floor', 'work_key' => 'floor', 'name' => 'Пол',
                'unit' => 'm2', 'quantity' => '10', 'quantity_formula' => 'floor.area',
                'source_fact_ids' => ['fact:floor'], 'technology_package_candidate' => null,
            ]], [], RunEstimateComposer::PROMPT_CONTRACT, new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(1));
    }
}
