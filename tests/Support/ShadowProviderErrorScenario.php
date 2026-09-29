<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditReservation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use RuntimeException;
use Throwable;

final class ShadowProviderErrorScenario
{
    public static function scenario(): array
    {
        return ['id' => 'provider-incomplete-real-receipt', 'category' => 'errors', 'requested_profile' => 'normal',
            'input' => ['message' => 'Объясни подробно, как проверять текущие данные проекта и права перед ответом. Напиши не менее двадцати полных предложений. Не выполняй действий.',
                'request_id' => '50000000-0000-4000-8000-000000000001',
                'context' => ['role' => 'owner', 'state' => 'active', 'coverage_contract' => 'real_incomplete_provider_receipt']],
            'assertions' => array_fill_keys(['rights', 'leak', 'unconfirmed_actions', 'factual_amounts', 'business_quality'], ['expected' => true])];
    }

    public static function limitedProvider(LLMProviderInterface $provider): LLMProviderInterface
    {
        return new class($provider) implements LLMProviderInterface {
            public function __construct(private readonly LLMProviderInterface $provider) {}
            public function chat(array $messages, array $options = []): array
            {
                $options['max_completion_tokens'] = 1;
                return $this->provider->chat($messages, $options);
            }
            public function countTokens(string $text): int { return $this->provider->countTokens($text); }
            public function isAvailable(): bool { return $this->provider->isAvailable(); }
            public function getModel(): string { return $this->provider->getModel(); }
        };
    }

    public static function execute(User $actor, Organization $org, Project $project, array $scenario, ShadowProviderCollector $collector): array
    {
        if (!app()->environment('testing') || config('ai-assistant-credits.enforce') !== false || config('ai-assistant.llm.provider') !== 'timeweb') {
            throw new RuntimeException('Real incomplete evaluation requires isolated shadow Timeweb execution.');
        }
        $container = app();
        $originalProvider = $container->make(LLMProviderInterface::class);
        if (!$originalProvider instanceof TimewebProvider) { throw new RuntimeException('Real incomplete evaluation requires the actual Timeweb provider.'); }
        $credits = $container->make(AICreditService::class);
        $beforeBalance = $credits->balance($org);
        $beforeBusiness = ShadowObservationVerifier::domainState();
        $offset = count($collector->calls);
        $response = null;
        $failure = null;
        try {
            $response = self::withLimitedProvider(fn (): array => $container->make(AIAssistantService::class)->ask($scenario['input']['message'], (int) $org->id, $actor, null,
                ['request_id' => $scenario['input']['request_id'], 'profile' => 'normal', 'project_id' => $project->id, 'allow_actions' => false]));
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $request = AssistantRequest::query()->where('organization_id', $org->id)->where('user_id', $actor->id)
            ->where('request_id', $scenario['input']['request_id'])->first();
        $reservation = $request === null ? null : AICreditReservation::query()->find($request->reservation_id);
        $rows = $reservation === null ? [] : AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->get()->toArray();
        $published = $request?->conversation_id === null ? null : Message::query()->where('conversation_id', $request->conversation_id)->where('role', 'assistant')->count();
        $afterBalance = $credits->balance($org);
        $calls = array_slice($collector->calls, $offset);
        $checks = self::receiptChecks($calls, $rows, $request?->request_id, $request?->status, $reservation?->status,
            $reservation?->consumed_minor, $published, $beforeBalance, $afterBalance, $failure instanceof AssistantResponseIncomplete, $response === null);
        $checks['current_rights'] = (int) $actor->current_organization_id === (int) $org->id
            && app(AuthorizationService::class)->canCurrent($actor, 'ai_assistant.chat', ['organization_id' => $org->id]);
        $checks['business_unchanged'] = $beforeBusiness === ShadowObservationVerifier::domainState();
        $evidence = ['checks' => $checks, 'provider_calls' => $calls, 'failed_journal_rows' => $rows,
            'request' => $request?->toArray(), 'reservation' => $reservation?->toArray(), 'published_assistant_messages' => $published,
            'balance_before' => $beforeBalance, 'balance_after' => $afterBalance,
            'failure' => $failure === null ? null : ShadowObservationVerifier::diagnostic($failure, 'real_provider_incomplete')];
        $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
        $passed = !in_array(false, $checks, true);
        $assertions = [];
        foreach (['rights', 'leak', 'unconfirmed_actions', 'factual_amounts', 'business_quality'] as $key) {
            $assertions[$key] = ['expected' => true, 'observed' => $passed, 'verifier' => self::class.'::receiptChecks', 'evidence_sha256' => $hash];
        }
        return ['response' => null, 'error' => $failure?->getMessage(), 'evidence' => $evidence, 'assertions' => $assertions,
            'executed_request_id' => $scenario['input']['request_id'], 'expected_technical_error' => $passed];
    }

    public static function withLimitedProvider(callable $execute): mixed
    {
        $container = app();
        $provider = $container->make(LLMProviderInterface::class);
        $main = $container->make(AIAssistantService::class);
        $binding = $container->getBindings()[LLMProviderInterface::class] ?? null;
        try {
            $container->instance(LLMProviderInterface::class, self::limitedProvider($provider));
            $container->forgetInstance(AIAssistantService::class);
            return $execute();
        } finally {
            if ($binding !== null) { $container->bind(LLMProviderInterface::class, $binding['concrete'], $binding['shared']); }
            $container->instance(LLMProviderInterface::class, $provider);
            $container->instance(AIAssistantService::class, $main);
        }
    }

    public static function receiptChecks(array $calls, array $rows, ?string $requestId, ?string $requestStatus, ?string $reservationStatus,
        ?int $consumed, ?int $published, array $before, array $after, bool $incomplete, bool $noResponse): array
    {
        $verified = [];
        foreach ($calls as $call) {
            $body = json_decode((string) ($call['raw_provider_response'] ?? ''), true);
            if (($call['kind'] ?? null) === 'assistant' && ($call['success'] ?? null) === false && ($body['status'] ?? null) === 'incomplete'
                && ($body['incomplete_details']['reason'] ?? null) === 'max_output_tokens' && ($call['usage_source'] ?? null) === 'provider_response'
                && ($call['evidence'] ?? null) === 'provider_usage' && is_int($body['usage']['input_tokens'] ?? null)
                && is_int($body['usage']['output_tokens'] ?? null) && $body['usage']['input_tokens'] > 0
                && $body['usage']['output_tokens'] > 0 && ($call['cost_micro_rub'] ?? 0) > 0
                && ($call['input_tokens'] ?? null) === $body['usage']['input_tokens']
                && ($call['output_tokens'] ?? null) === $body['usage']['output_tokens']
                && ($call['provider_evidence_sha256'] ?? null) === hash('sha256', (string) $call['raw_provider_response'])) { $verified[] = $call; }
        }
        $journal = false;
        foreach ($verified as $call) {
            foreach ($rows as $row) {
                $metadata = $row['metadata'] ?? [];
                if (($row['is_successful'] ?? null) === false && ($metadata['provider_usage_available'] ?? null) === true
                    && ($metadata['cost_available'] ?? null) === true && str_starts_with((string) ($row['usage_key'] ?? ''), (string) $requestId.':')
                    && ($metadata['input_tokens'] ?? null) === $call['input_tokens'] && ($metadata['output_tokens'] ?? null) === $call['output_tokens']
                    && ($row['cost_micro_rub'] ?? null) === $call['cost_micro_rub']) { $journal = true; }
            }
        }
        return ['real_incomplete_receipt' => $incomplete && $verified !== [], 'failed_cost_journal' => $requestId !== null && $journal,
            'failed_request' => $requestStatus === 'failed', 'released_reservation' => $reservationStatus === 'cancelled',
            'zero_user_debit' => $consumed === 0 && is_int($before['available_minor'] ?? null)
                && $before['available_minor'] === ($after['available_minor'] ?? null)
                && ($before['reserved_minor'] ?? null) === 0 && ($after['reserved_minor'] ?? null) === 0,
            'no_partial_publication' => $noResponse && $published === 0];
    }
}
