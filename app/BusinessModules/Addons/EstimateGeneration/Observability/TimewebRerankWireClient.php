<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use OpenAI;
use Throwable;

final class TimewebRerankWireClient implements RerankWireClient
{
    public function __construct(
        private readonly TimewebChatCompletionPayloadFactory $payloadFactory,
        private readonly SessionAiCostGuard $costGuard,
        private readonly DurableAiPhysicalResponseStore $responses,
        private readonly ?Closure $transport = null,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    public function provider(): string
    {
        return 'timeweb';
    }

    public function call(string $model, array $messages, array $options): array
    {
        try {
            \App\Support\AI\LunaModelPolicy::assert($model, 'timeweb');
            $scope = $options['estimate_generation_scope'] ?? null;
            $identity = $options['estimate_generation_attempt'] ?? null;
            if (! is_array($scope) || ! is_array($identity)) {
                throw new AiWireNotStarted('text_ai_wire_scope_required');
            }
            // Caller options cannot override the server's frozen operation policy.
            unset($options['estimate_generation_frozen_text_policy']);
            $policy = EstimatorTextWirePolicy::forOperation((int) ($scope['organization_id'] ?? 0),
                (int) ($scope['project_id'] ?? 0), (int) ($scope['session_id'] ?? 0),
                is_string($scope['credit_operation_id'] ?? null) ? $scope['credit_operation_id'] : null,
                (string) ($identity['operation'] ?? ''));
            if ($policy !== null) {
                $options['estimate_generation_frozen_text_policy'] = $policy;
            }
            $payload = $this->payloadFactory->make($model, $messages, $options);
            $timeout = min(3600, max(1, (int) ($options['timeout'] ?? config('ai-assistant.llm.timeweb.timeout', 25))));
            $price = AiPriceSnapshot::fromArray((array) ($identity['price_snapshot'] ?? []));
            $reservation = (new AiCostCalculator)->calculate(
                EstimatorTextWirePolicy::serializedInputUpper($payload['messages']),
                0, (int) $payload['max_completion_tokens'],
                $price->reasoningMode === 'excluded_from_output' ? (int) $payload['max_completion_tokens'] : 0,
                0, 0, $price->toArray(),
            );
            $attempt = new TextAiWireAttempt(
                (string) ($identity['attempt_id'] ?? ''),
                (string) ($identity['request_fingerprint'] ?? ''),
                $reservation, $timeout,
                is_string($scope['generation_attempt_id'] ?? null) ? $scope['generation_attempt_id'] : null,
                is_int($scope['state_version'] ?? null) ? $scope['state_version'] : null,
                $options['estimate_generation_trusted_actor'] ?? null,
                (string) ($options['estimate_generation_permission'] ?? 'estimate_generation.generate'),
                $options['estimate_generation_before_send'] ?? null,
                is_string($scope['credit_operation_id'] ?? null) ? $scope['credit_operation_id'] : null,
                is_string($identity['operation'] ?? null) ? $identity['operation'] : null,
            );
        } catch (AiWireNotStarted $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiWireNotStarted('text_ai_wire_preparation_failed', 0, $exception);
        }
        $organizationId = (int) ($scope['organization_id'] ?? 0);
        $projectId = (int) ($scope['project_id'] ?? 0);
        $sessionId = (int) ($scope['session_id'] ?? 0);
        try {
            $replay = $this->responses->replayWire($organizationId, $projectId, $sessionId, $attempt->attemptId, $attempt->requestFingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $send = $this->transport ?? $this->makeTransport($timeout);
            $this->costGuard->authorize(
                (int) ($scope['organization_id'] ?? 0),
                (int) ($scope['project_id'] ?? 0),
                (int) ($scope['session_id'] ?? 0),
                $attempt,
            );
        } catch (AiWireNotStarted $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiWireNotStarted('text_ai_wire_admission_failed', 0, $exception);
        }
        $started = hrtime(true);
        $received = [];
        try {
            $response = $send($payload);
            $durationMs = (int) max(0, round((hrtime(true) - $started) / 1_000_000));
            $received = [...$response, 'physical_attempt_receipt' => [
                'duration_ms' => $durationMs, 'price_snapshot' => $price->toArray(), 'usage_recorded' => false,
            ]];
            $this->responses->storeWire($organizationId, $projectId, $sessionId,
                $attempt->attemptId, $attempt->requestFingerprint, $response, $durationMs, $price->toArray());

            return $received;
        } catch (RerankWireException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            throw new RerankWireException(
                $code >= 100 && $code <= 599 ? 'http_failed' : 'connection_failed',
                $code >= 100 && $code <= 599 ? $code : null,
                $received,
            );
        }
    }

    private function makeTransport(int $timeout): Closure
    {
        $apiKey = trim((string) config('ai-assistant.llm.timeweb.api_key', ''));
        if ($apiKey === '') {
            throw new AiWireNotStarted('reranker_not_configured');
        }
        $client = OpenAI::factory()->withApiKey($apiKey)
            ->withBaseUri((string) config('ai-assistant.llm.timeweb.base_uri', 'https://api.timeweb.ai/v1'))
            ->withHttpClient($this->httpClient ?? new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => min(5, $timeout)]))->make();

        return static function (array $payload) use ($client): array {
            $response = $client->chat()->create($payload);
            $choice = $response->choices[0] ?? null;
            $message = $choice?->message;
            $usage = $response->usage ?? null;

            return [
                'content' => is_object($message) ? (string) ($message->content ?? '') : '',
                'model' => (string) ($response->model ?? $payload['model']),
                'input_tokens' => is_object($usage) ? max(0, (int) ($usage->promptTokens ?? 0)) : 0,
                'output_tokens' => is_object($usage) ? max(0, (int) ($usage->completionTokens ?? 0)) : 0,
                'cached_input_tokens' => is_object($usage) ? max(0, (int) ($usage->promptTokensDetails?->cachedTokens ?? 0)) : 0,
                'reasoning_tokens' => is_object($usage) ? max(0, (int) ($usage->completionTokensDetails?->reasoningTokens ?? 0)) : 0,
                'usage_available' => is_object($usage),
                'finish_reason' => is_string($choice?->finishReason) ? $choice->finishReason : null,
            ];
        };
    }
}
