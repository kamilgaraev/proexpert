<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\Models\Project;
use App\Services\Logging\LoggingService;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\ShadowProviderCollector;
use Tests\Support\ShadowScenarioManifest;
use Tests\Support\ShadowObservationVerifier;
use Tests\TestCase;
use Throwable;

final class AssistantShadowEvaluationTest extends TestCase
{
    private static function helper(string $category): ?string
    {
        $name = match ($category) {
            'errors' => 'ShadowProviderErrorScenario',
            'followup', 'context', 'memory', 'retention', 'isolation' => 'ShadowConversationScenario',
            'index' => 'ShadowIndexingScenario',
            'files', 'ocr', 'screenshots' => 'ShadowDocumentScenario',
            'actions', 'billing', 'races', 'tokens', 'reports', 'navigation' => 'ShadowActionBillingScenario',
            default => 'ShadowDomainScenario',
        };
        $class = 'Tests\\Support\\'.$name;
        return class_exists($class) ? $class : null;
    }
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AI_SHADOW_EVALUATION') !== '1') {
            self::markTestSkipped('Opt-in real shadow evaluation is disabled.');
        }
        ShadowObservationVerifier::progress('bootstrap', 'setup');
        $connection = config('database.connections.'.config('database.default'));
        self::assertSame('testing', app()->environment());
        self::assertSame('pgsql', $connection['driver']);
        self::assertSame('127.0.0.1', $connection['host']);
        self::assertSame((string) \Tests\Support\IsolatedPostgresTestDatabase::profilePort(), (string) $connection['port']);
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/D', $connection['database']);
        self::assertFalse((bool) config('ai-assistant-credits.enforce'));
    }

    public function test_real_shadow_scenarios_emit_observed_evidence(): void
    {
        $startedAt = now()->toAtomString();
        $manifest = ShadowScenarioManifest::load(false, getenv('AI_SHADOW_SUPPLEMENTAL') === '1');
        foreach ($manifest as &$entry) {
            $helper = self::helper($entry['category']);
            if ($helper !== null && method_exists($helper, 'prepare')) { $entry = $helper::prepare($entry); }
        }
        unset($entry);
        self::assertGreaterThanOrEqual(200, count($manifest));
        $pricing = (array) config('ai-assistant-credits.pricing');
        $pricing += [
            'unit_minor' => (int) config('ai-assistant-credits.unit_minor'),
            'unit_cost_micro_rub' => (int) round((float) config('ai-assistant-credits.rub_per_unit') * 1_000_000),
            'minimum_units_minor' => (int) config('ai-assistant-credits.minimum_units_minor'),
            'charge_step_minor' => (int) config('ai-assistant-credits.charge_step_minor'),
        ];
        $priceEvidence = json_decode((string) file_get_contents(storage_path('app/private/assistant-shadow-bridge/pricing-evidence.json')), true, 512, JSON_THROW_ON_ERROR);
        $priceEvidence['html_verified'] = hash_file('sha256', storage_path('app/private/assistant-shadow-bridge/timeweb-pricing-page.html')) === $priceEvidence['html_sha256'];
        $priceEvidence['evidence_file_sha256'] = hash_file('sha256', storage_path('app/private/assistant-shadow-bridge/pricing-evidence.json'));
        $collector = new ShadowProviderCollector((string) getenv('AI_SHADOW_RELAY_URL'), (string) getenv('AI_SHADOW_RELAY_TOKEN'), $pricing, $priceEvidence);
        $client = $collector->client();
        $transportReceipts = [];
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(\GuzzleHttp\HandlerStack::class, $stack);
        $stack->push(static function (callable $handler) use (&$transportReceipts): callable {
            return static function (\Psr\Http\Message\RequestInterface $request, array $options) use ($handler, &$transportReceipts) {
                $raw = (string) $request->getBody();
                $payload = json_decode($raw, true);
                $strings = array_filter(\Illuminate\Support\Arr::flatten(is_array($payload) ? $payload : []), 'is_string');
                $transportReceipts[] = ['path' => $request->getUri()->getPath(), 'request_body_sha256' => hash('sha256', $raw),
                    'text_sha256' => array_map(static fn (string $value): string => hash('sha256', $value), array_values($strings))];
                return $handler($request, $options);
            };
        }, 'shadow_current_query_provenance');
        $this->app->instance(ClientInterface::class, $client);
        config(['ai-assistant.llm.timeweb.api_key' => 'shadow-relay-placeholder', 'ai-assistant.llm.openai.api_key' => 'shadow-relay-placeholder']);
        $this->app->bind(TimewebProvider::class, fn () => new TimewebProvider(app(LoggingService::class), $client));
        $this->app->bind(OpenAIProvider::class, fn () => new OpenAIProvider(app(LoggingService::class), $client));
        $this->app->forgetInstance(\App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface::class);
        $this->app->forgetInstance(AIAssistantService::class);
        Http::globalOptions(['handler' => $collector->handler()]);
        config(['ai-assistant.rag.embedding_model' => 'openai/text-embedding-3-large', 'ai-assistant.rag.embedding_dimensions' => 256, 'ai-assistant.rag.embedding_base_uri' => 'https://api.timeweb.ai/v1']);
        $embeddingModel = (string) config('ai-assistant.rag.embedding_model');
        $embeddingBase = (string) config('ai-assistant.rag.embedding_base_uri', 'https://api.openai.com/v1');
        $embeddingSdk = \OpenAI::factory()->withApiKey('shadow-relay-placeholder')->withBaseUri($embeddingBase ?: 'https://api.openai.com/v1')->withHttpClient($client)->make();
        $this->app->instance(RagEmbeddingProviderInterface::class, new OpenAIRagEmbeddingProvider($embeddingSdk, 'shadow-relay-placeholder', $embeddingModel, 256, $embeddingBase, 'timeweb'));
        ShadowObservationVerifier::progress('bootstrap', 'authorization_fixture');
        $fixtures = AssistantRealAuthorizationFixture::create(array_column(app(\App\Services\Modules\PackageCatalogService::class)->allPackages(), 'slug'));
        $canary = 'shadow_foreign_'.bin2hex(random_bytes(16));
        Project::factory()->create(['organization_id' => $fixtures->foreignOrganization->id, 'name' => $canary]);
        $project = Project::factory()->create(['organization_id' => $fixtures->organization->id, 'name' => 'Проверка помощника', 'is_archived' => false]);
        $fixtures->administrator->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $revoked = $fixtures->addMember(['ai-assistant' => ['ai_assistant.chat']]);
        $revoked->organizations()->updateExistingPivot($fixtures->organization->id, ['is_active' => false]);
        $actors = [$fixtures->owner, $fixtures->member, $fixtures->administrator, $revoked];
        $readPermissions = ['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports', 'ai_assistant.analytics', 'ai_assistant.usage']];
        foreach (['estimates', 'contracts', 'finance', 'works', 'warehouse', 'machinery', 'crm'] as $domain) {
            $definition = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog::class)->definition($domain);
            self::assertNotNull($definition);
            $permissions = \Illuminate\Support\Arr::flatten([$definition->permissions, array_values($definition->entityPermissions), array_values($definition->fieldPermissions)]);
            self::assertContainsOnly('string', $permissions);
            $readPermissions[$definition->module] = array_values(array_unique(array_merge($readPermissions[$definition->module] ?? [], $permissions)));
        }
        foreach (['assigned_finance_reader', 'assigned_reader'] as $assignedRole) {
            $assignedActor = $fixtures->addMember($readPermissions);
            $assignedActor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
            $actors[] = $assignedActor;
        }
        foreach ($manifest as &$entry) {
            $contextIndex = match ($entry['input']['context']['role']) { 'owner' => 0, 'project_manager' => 2, 'finance_manager' => 1, 'viewer' => 3,
                'assigned_finance_reader' => 4, 'assigned_reader' => 5, default => 0 };
            $contextActor = $actors[$contextIndex];
            $entry['input']['original_context'] = $entry['input']['context'];
            $entry['input']['context'] = array_merge($entry['input']['context'], ['actor_id' => $contextActor->id, 'organization_id' => $fixtures->organization->id,
                'project_id' => $project->id, 'role' => ['organization_owner', 'restricted_member', 'organization_admin', 'revoked_member', 'assigned_finance_reader', 'assigned_reader'][$contextIndex],
                'state' => $contextIndex === 3 ? 'access_revoked' : 'active', 'context_index' => $contextIndex]);
        }
        unset($entry);
        $trace = ['schema_version' => 2, 'scenario_contract' => 'domain_v1', 'economics_basis' => 'prelaunch_projection', 'economics_version' => 1,
            'assistant_implementation_fingerprint' => app(\App\Services\Credits\AICreditReadinessService::class)->assistantImplementationFingerprint(),
            'price_policy_evidence' => app(\App\Services\Credits\AICreditReadinessService::class)->prelaunchPricePolicyEvidence(), 'charging_mode' => 'shadow', 'stage' => 'actual', 'assistant_revenue_minor' => 0,
            'period' => ['started_at' => $startedAt, 'ended_at' => now()->toAtomString()], 'policy_hash' => app(\App\Services\Credits\AICreditReadinessService::class)->policyFingerprint(),
            'pricing_snapshot' => $pricing, 'cost_coverage' => [], 'scenarios' => [], 'background_calls' => [],
            'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
        ];
        $directory = storage_path('app/private/assistant-shadow');
        if (! is_dir($directory)) { mkdir($directory, 0700, true); }
        file_put_contents($directory.'/workflow-intents-pending.json', json_encode(ShadowScenarioManifest::load(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $limitValue = getenv('AI_SHADOW_SCENARIO_LIMIT');
        $limit = $limitValue === false || $limitValue === '' ? count($manifest) : (int) $limitValue;
        self::assertGreaterThan(0, $limit);
        self::assertLessThanOrEqual(count($manifest), $limit);
        $selected = array_slice($manifest, 0, $limit);
        $filter = getenv('AI_SHADOW_SCENARIO_FILTER');
        if ($filter !== false && $filter !== '') {
            $selected = array_values(array_filter($manifest, static fn (array $entry): bool => $entry['id'] === $filter));
            self::assertCount(1, $selected, 'Shadow filter must name exactly one manifest scenario.');
        }
        $trace['partial_run'] = count($selected) < count($manifest);
        $limit = count($selected);
        $firstErrorWritten = false;
        file_put_contents($directory.'/first-error.json', json_encode(['first_error' => null], JSON_THROW_ON_ERROR));
        foreach ($selected as $scenario) {
            self::assertSame($trace['assistant_implementation_fingerprint'], app(\App\Services\Credits\AICreditReadinessService::class)->assistantImplementationFingerprint(true), 'Assistant sources changed during the shadow run.');
            $collector->activeKind = 'assistant';
            $collector->activeScenario = $scenario['id'];
            $offset = count($collector->calls);
            $transportOffset = count($transportReceipts);
            ShadowObservationVerifier::progress($scenario['id'], 'setup', ['completed' => count($trace['scenarios']), 'total' => $limit]);
            $before = [];
            $actor = $actors[$scenario['input']['context']['context_index']];
            $result = null;
            $categoryObservation = null;
            $error = null;
            $scenarioLevel = DB::connection()->transactionLevel();
            try {
                DB::beginTransaction();
                ShadowObservationVerifier::progress($scenario['id'], 'domain_snapshot_before_fixture');
                $before = ShadowObservationVerifier::domainState();
                ShadowObservationVerifier::progress($scenario['id'], 'domain_snapshot_before_fixture_done');
                $helper = self::helper($scenario['category']);
                $categoryObservation = null;
                if ($helper !== null) {
                    $categoryObservation = $scenario['category'] === 'index'
                        ? app($helper)->execute($fixtures->organization, $project, $scenario['id'], $scenario)
                        : app($helper)->execute($actor, $fixtures->organization, $project, $scenario, $collector);
                    $result = $categoryObservation['response'] ?? null;
                    if ($helper === \Tests\Support\ShadowProviderErrorScenario::class) {
                        $error = $categoryObservation['error'] ?? 'expected_incomplete_not_observed';
                    }
                }
                if ($result === null && ! isset($categoryObservation['error'])) {
                    ShadowObservationVerifier::progress($scenario['id'], 'fallback_domain_snapshot', ['completed' => count($trace['scenarios']), 'total' => $limit]);
                    $before = ShadowObservationVerifier::domainState();
                    ShadowObservationVerifier::progress($scenario['id'], 'fallback_ask', ['completed' => count($trace['scenarios']), 'total' => $limit]);
                    $result = app(AIAssistantService::class)->ask($scenario['input']['message'], $fixtures->organization->id, $actor, null,
                        ['request_id' => $scenario['input']['request_id'], 'profile' => $scenario['requested_profile'], 'project_id' => $categoryObservation['request_context']['project_id'] ?? $project->id, 'allow_actions' => false]);
                    ShadowObservationVerifier::progress($scenario['id'], 'ask_returned', ['completed' => count($trace['scenarios']), 'total' => $limit]);
                }
                DB::commit();
            } catch (Throwable $exception) {
                $diagnostic = ShadowObservationVerifier::diagnostic($exception, 'fixture_or_ask');
                if (! $firstErrorWritten) {
                    file_put_contents($directory.'/first-error.json', json_encode($diagnostic + ['scenario_id' => $scenario['id']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    $firstErrorWritten = true;
                }
                if (DB::connection()->transactionLevel() > $scenarioLevel) { DB::rollBack($scenarioLevel); }
                $error = $exception::class;
                $categoryObservation = ['response' => null, 'error' => $diagnostic, 'evidence' => ['first_error' => $diagnostic]];
                ShadowObservationVerifier::progress($scenario['id'], 'failed', ['first_error' => $diagnostic, 'completed' => count($trace['scenarios'])]);
            }
            if ($scenario['category'] === 'index' && is_array($categoryObservation['checks'] ?? null) && $categoryObservation['checks'] !== []) {
                $checksPassed = ! in_array(false, array_map(static fn (array $check): bool => ($check['passed'] ?? false) === true, $categoryObservation['checks']), true);
                $categoryObservation['assertions']['business_quality'] = ['expected' => true, 'observed' => $checksPassed,
                    'verification_scope' => 'independent_source_index_checksum_reuse_pruning_and_lease_oracles',
                    'observed_detail' => $categoryObservation['checks'], 'verifier' => 'Tests\\Support\\ShadowIndexingScenario',
                    'evidence_sha256' => $categoryObservation['evidence_sha256']];
            }
            $calls = array_slice($collector->calls, $offset);
            $receipt = ['response' => $result, 'error_class' => $error, 'actor_id' => $actor->id, 'organization_id' => $fixtures->organization->id,
                'category_observation' => $categoryObservation, 'project' => $project->only(['id', 'organization_id', 'name', 'updated_at']), 'provider_calls' => $calls];
            try {
                $verification = DB::transaction(fn (): array => ShadowObservationVerifier::verify($actor, $fixtures->organization->id, $result, $before, $canary));
            } catch (Throwable $exception) {
                $diagnostic = ShadowObservationVerifier::diagnostic($exception, 'verify');
                $receipt['verification_error'] = $diagnostic;
                $verification = ['evidence' => [], 'observed' => array_fill_keys(array_keys($scenario['expectations']), 'failed: verification SQL or invariant failure')];
                if ($error === null) { $error = $exception::class; $categoryObservation['error'] = $diagnostic; }
                file_put_contents($directory.'/verification-error.json', json_encode($diagnostic + ['scenario_id' => $scenario['id']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            $receipt['database_verification'] = $verification;
            $commerceUnchanged = ($before['commercial_orders'] ?? null) === ($verification['evidence']['domain_after']['commercial_orders'] ?? null);
            $hash = hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR));
            $assertions = [];
            foreach ($scenario['expectations'] as $key => $expected) {
                $assertions[$key] = ['expected' => $expected, 'observed' => $verification['observed'][$key],
                    'verifier' => self::class, 'evidence_sha256' => $hash];
            }
            foreach ((array) ($categoryObservation['assertions'] ?? []) as $key => $check) {
                if (isset($assertions[$key]) && is_array($check)) {
                    $baseFailed = in_array($key, ['rights', 'leak'], true) && str_starts_with((string) $assertions[$key]['observed'], 'failed:');
                    if (! $baseFailed) { $assertions[$key] = $check; }
                }
            }
            foreach ($assertions as $key => &$check) {
                $detail = $check['observed'];
                $check['expected_description'] = $scenario['expectation_descriptions'][$key] ?? $check['expected'];
                $check['expected'] = true;
                $check['observed_detail'] ??= $detail;
                $check['observed'] = is_bool($detail) ? $detail : (is_string($detail) && str_starts_with($detail, 'passed:'));
            }
            unset($check);
            if (! $commerceUnchanged) {
                $assertions['unconfirmed_actions']['observed'] = false;
                $assertions['unconfirmed_actions']['observed_detail'] = 'failed: commercial orders changed during the shadow scenario';
            }
            $request = \App\BusinessModules\Features\AIAssistant\Models\AssistantRequest::query()->where('organization_id', $fixtures->organization->id)->where('request_id', $categoryObservation['executed_request_id'] ?? $scenario['input']['request_id'])->first();
            $reservation = $request === null ? null : \App\Models\Credits\AICreditReservation::query()->find($request->reservation_id);
            $quote = $reservation === null ? null : \App\Models\Credits\AICreditQuote::query()->find($reservation->ai_credit_quote_id);
            $scenarioPricing = $quote?->pricing ?? $pricing;
            $scenario['approved_minor'] = $request === null ? 0 : (int) $request->approved_max_minor;
            $scenario['estimated_minor'] = $quote === null ? 0 : (int) $quote->max_units_minor;
            $ocrQuoteId = $categoryObservation['server_quote_evidence']['quote_id'] ?? null;
            $ocrQuote = is_string($ocrQuoteId) ? \App\Models\Credits\AICreditQuote::query()->where('organization_id', $fixtures->organization->id)->where('public_id', $ocrQuoteId)->first() : null;
            $ocrReservation = $ocrQuote === null ? null : \App\Models\Credits\AICreditReservation::query()->where('ai_credit_quote_id', $ocrQuote->id)->first();
            if ($ocrQuote !== null) {
                $scenario['approved_minor'] += (int) $ocrQuote->max_units_minor;
                $scenario['estimated_minor'] += (int) $ocrQuote->max_units_minor;
                $scenarioPricing = $quote?->pricing ?? $ocrQuote->pricing;
            }
            $budgetReceipt = ['ocr_quote' => $ocrQuote?->only(['public_id', 'request_hash', 'pricing', 'limits', 'max_units_minor', 'created_at']),
                'ocr_consumed_minor' => $ocrReservation?->consumed_minor, 'budget_scope' => 'assistant_request_plus_independently_confirmed_ocr', 'approval_mode' => 'shadow_server_quote_no_paid_confirmation', 'request_id' => $request?->request_id, 'request_hash' => $request?->request_hash, 'quote_id' => $quote?->public_id,
                'quote_created_at' => $quote?->created_at?->toAtomString(), 'reservation_id' => $reservation?->public_id, 'pricing' => $scenarioPricing,
                'limits' => $quote?->limits, 'approved_maximum_minor' => $scenario['approved_minor'], 'consumed_minor' => $reservation?->consumed_minor];
            $receipt['immutable_server_budget'] = $budgetReceipt;
            if (($scenario['input']['context']['long_current_query'] ?? false) === true) {
                $actualQuery = $categoryObservation['evaluated_input']['message'] ?? $categoryObservation['evidence']['evaluated_input']['message'] ?? '';
                $queryHash = hash('sha256', $actualQuery);
                $sceneTransport = array_slice($transportReceipts, $transportOffset);
                $receipt['current_query_transport'] = ['query_sha256' => $queryHash, 'unicode_length' => mb_strlen($actualQuery),
                    'request_receipts' => $sceneTransport, 'verified' => count(array_filter($sceneTransport, static fn (array $wire): bool => in_array($queryHash, $wire['text_sha256'], true))) > 0];
            }
            $receipt['assistant_call_budget_checks'] = [];
            foreach ($calls as $call) {
                if ($call['kind'] !== 'assistant') { continue; }
                $limits = (array) ($quote?->limits ?? []);
                $receipt['assistant_call_budget_checks'][] = ['provider_evidence_sha256' => $call['provider_evidence_sha256'],
                    'success' => $call['success'],
                    'usage_available' => $call['usage_source'] === 'provider_response',
                    'input_tokens' => $call['input_tokens'], 'output_tokens' => $call['output_tokens'],
                    'approved_input_tokens' => $limits['input_tokens'] ?? null, 'approved_output_tokens' => $limits['output_tokens'] ?? null,
                    'within_approved_input' => isset($limits['input_tokens']) && $call['usage_source'] === 'provider_response' && $call['input_tokens'] <= $limits['input_tokens'],
                    'within_approved_output' => isset($limits['output_tokens']) && $call['usage_source'] === 'provider_response' && $call['output_tokens'] <= $limits['output_tokens']];
            }
            $hash = hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR));
            $outcome = ($categoryObservation['expected_technical_error'] ?? false) ? 'error'
                : ($result !== null ? 'completed' : (($categoryObservation['expected_rejection'] ?? false) || ($categoryObservation['evidence']['readable_at_execution'] ?? true) === false ? 'blocked' : 'error'));
            $leak = str_contains(json_encode($result, JSON_THROW_ON_ERROR), $canary);

            $cost = array_sum(array_map(static fn (array $call): int => $call['success'] && in_array($call['kind'], ['assistant', 'ocr', 'memory'], true) ? (int) ($call['cost_micro_rub'] ?? 0) : 0, $calls));
            $units = (int) ceil($cost * $scenarioPricing['unit_minor'] / $scenarioPricing['unit_cost_micro_rub']);
            $projected = $result === null ? 0 : max($scenarioPricing['minimum_minor'] ?? $scenarioPricing['minimum_units_minor'], (int) ceil($units / $scenarioPricing['charge_step_minor']) * $scenarioPricing['charge_step_minor']);
            $evaluatedInput = $categoryObservation['evaluated_input'] ?? $categoryObservation['evidence']['evaluated_input'] ?? null;
            if (is_array($evaluatedInput) && is_string($evaluatedInput['message'] ?? null)) {
                $scenario['original_input'] = $scenario['input'];
                $scenario['input']['message'] = $evaluatedInput['message'];
                $scenario['input']['context']['executed_project_id'] = $evaluatedInput['project_id'] ?? $project->id;
                $scenario['input']['context']['executed_entity_type'] = $categoryObservation['evidence']['entity_type'] ?? null;
                $scenario['input']['context']['executed_entity_id'] = $categoryObservation['evidence']['entity_id'] ?? null;
            }
            $readableUsefulSource = ($categoryObservation['evidence']['readable_at_execution'] ?? false) === true
                || (($categoryObservation['evidence']['document_readable_before_ask'] ?? false) === true && ($categoryObservation['evidence']['actual_attachment_workflow_contract']['expected_status'] ?? null) === 'ready');
            $useful = $outcome === 'completed' && ($assertions['business_quality']['observed'] ?? false) === true && $readableUsefulSource && $error === null;
            $trace['scenarios'][] = array_merge($scenario, ['outcome' => $outcome, 'provider_calls' => $calls, 'successful_cost_micro_rub' => $cost,
                'charged_minor' => (int) ($reservation?->consumed_minor ?? 0) + (int) ($ocrReservation?->consumed_minor ?? 0), 'projected_charge_minor' => $projected, 'pricing_snapshot' => ($quote !== null || $ocrQuote !== null) ? $scenarioPricing : null,
                'useful_outcome' => $useful, 'useful_evidence' => ['verified' => true, 'verifier' => self::class.'::independentUsefulOutcome', 'evidence_sha256' => $hash],
                'execution_evidence_sha256' => $hash, 'assertions' => $assertions, 'observation' => $receipt]);
            if (is_array($categoryObservation['server_read_evidence'] ?? null)) {
                $trace['scenarios'][array_key_last($trace['scenarios'])]['server_read_evidence'] = $categoryObservation['server_read_evidence'];
            }
            file_put_contents($directory.'/trace.json', json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (isset($receipt['current_query_transport']) && $result !== null) {
                self::assertSame(4000, $receipt['current_query_transport']['unicode_length']);
                self::assertTrue($receipt['current_query_transport']['verified'], 'Full current financial question was absent from the actual SDK request.');
                self::assertTrue($categoryObservation['evidence']['current_query_provenance']['persisted_user_message_matches']);
            }
            foreach ($receipt['assistant_call_budget_checks'] as $check) {
                if (! $check['success']) { continue; }
                self::assertTrue($check['usage_available'], $scenario['id'].' successful provider response omitted actual usage');
                self::assertTrue($check['within_approved_input'], $scenario['id'].' provider input exceeded immutable server profile budget');
                self::assertTrue($check['within_approved_output'], $scenario['id'].' provider output exceeded immutable server profile budget');
            }
            self::assertSame($trace['assistant_implementation_fingerprint'], app(\App\Services\Credits\AICreditReadinessService::class)->assistantImplementationFingerprint(true), 'Assistant sources changed during scenario execution.');
            self::assertFalse($leak, $scenario['id'].' exposed a foreign project canary');
            self::assertFalse((bool) config('ai-assistant-credits.enforce'));
            self::assertSame(0, (int) ($reservation?->consumed_minor ?? 0));
            self::assertSame(0, (int) ($ocrReservation?->consumed_minor ?? 0));
        }
        $trace['period']['ended_at'] = now()->toAtomString();
        $kinds = array_column($collector->calls, 'kind');
        $trace['cost_coverage'] = ['assistant' => in_array('assistant', $kinds, true), 'index' => in_array('index', $kinds, true),
            'ocr' => in_array('ocr', $kinds, true), 'errors' => in_array(false, array_column($collector->calls, 'success'), true),
            'memory' => ['mode' => 'pending', 'verified' => false]];
        foreach ($trace['scenarios'] as $executed) {
            $memoryEvidence = $executed['observation']['category_observation']['evidence']['memory_no_external'] ?? null;
            if (is_array($memoryEvidence) && ($memoryEvidence['verified'] ?? false) === true) {
                $trace['cost_coverage']['memory'] = $memoryEvidence;
            }
        }
        file_put_contents($directory.'/trace.json', json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $readiness = app(\App\Services\Credits\AICreditReadinessService::class)->evaluate($trace);
        $generationCount = count(array_filter($trace['scenarios'], static fn (array $entry): bool => $entry['outcome'] === 'completed'
            && ($entry['assertions']['business_quality']['observed'] ?? false) === true
            && count(array_filter($entry['provider_calls'], static fn (array $call): bool => $call['kind'] === 'assistant'
                && $call['success'] === true && ($call['usage_source'] ?? null) === 'provider_response'
                && preg_match('/^[a-f0-9]{64}$/', (string) ($call['provider_evidence_sha256'] ?? '')) === 1)) > 0));
        $outcomes = array_count_values(array_column($trace['scenarios'], 'outcome'));
        $spends = array_column(array_filter($trace['scenarios'], static fn (array $entry): bool => $entry['useful_outcome']), 'projected_charge_minor');
        sort($spends);
        $percentile = static fn (float $fraction): ?int => $spends === [] ? null : $spends[max(0, (int) ceil(count($spends) * $fraction) - 1)];
        $profileCosts = [];
        foreach ($trace['scenarios'] as $entry) {
            $profile = $entry['requested_profile'];
            $profileCosts[$profile]['attempt_count'] = ($profileCosts[$profile]['attempt_count'] ?? 0) + 1;
            $profileCosts[$profile]['external_cost_micro_rub'] = ($profileCosts[$profile]['external_cost_micro_rub'] ?? 0) + array_sum(array_column($entry['provider_calls'], 'cost_micro_rub'));
        }
        file_put_contents($directory.'/readiness.json', json_encode($readiness, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/report.json', json_encode($readiness + ['scenario_contract' => 'domain_v1', 'partial_run' => $trace['partial_run'], 'executed' => count($trace['scenarios']),
            'verified_model_quality_success_count' => $generationCount, 'model_quality_minimum_met' => ($readiness['verified_model_quality_success_count'] ?? 0) >= 200,
            'useful_projected_charge_minor_distribution' => ['count' => count($spends), 'p50' => $percentile(0.5), 'p90' => $percentile(0.9), 'target_minor' => [50, 150]],
            'all_attempts_by_profile' => $profileCosts,
            'outcome_counts' => $outcomes, 'model_quality_count_basis' => 'Completed independently verified business answer with a successful assistant call and raw provider usage receipt.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
