<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use RuntimeException;
use Throwable;

final class TimewebRerankWireClient implements RerankWireClient
{
    public function __construct(
        private readonly TimewebChatCompletionPayloadFactory $payloadFactory,
    ) {}

    public function provider(): string
    {
        return 'timeweb';
    }

    public function call(string $model, array $messages, array $options): array
    {
        $apiKey = trim((string) config('ai-assistant.llm.timeweb.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('reranker_not_configured');
        }
        $timeout = max(1, (int) ($options['timeout'] ?? config('ai-assistant.llm.timeweb.timeout', 25)));
        $client = OpenAI::factory()->withApiKey($apiKey)
            ->withBaseUri((string) config('ai-assistant.llm.timeweb.base_uri', 'https://api.timeweb.ai/v1'))
            ->withHttpClient(new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => min(5, $timeout)]))->make();
        try {
            $response = $client->chat()->create(
                $this->payloadFactory->make($model, $messages, $options),
            );
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            throw new RerankWireException(
                $code >= 100 && $code <= 599 ? 'http_failed' : 'connection_failed',
                $code >= 100 && $code <= 599 ? $code : null,
            );
        }
        $choice = $response->choices[0] ?? null;
        $message = $choice?->message;
        if (! is_object($message)) {
            throw new RerankWireException('malformed_response');
        }
        $usage = $response->usage ?? null;

        return [
            'content' => (string) ($message->content ?? ''),
            'model' => (string) ($response->model ?? $model),
            'input_tokens' => is_object($usage) ? max(0, (int) ($usage->promptTokens ?? 0)) : 0,
            'output_tokens' => is_object($usage) ? max(0, (int) ($usage->completionTokens ?? 0)) : 0,
            'usage_available' => is_object($usage),
            'finish_reason' => is_string($choice?->finishReason) ? $choice->finishReason : null,
        ];
    }
}
