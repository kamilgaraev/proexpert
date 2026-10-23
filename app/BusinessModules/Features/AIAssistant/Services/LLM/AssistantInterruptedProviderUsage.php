<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use OpenAI\Responses\Responses\CreateResponse;
use Throwable;
use WeakMap;

final class AssistantInterruptedProviderUsage
{
    private static ?WeakMap $receipts = null;

    private function __construct(
        private readonly int $input,
        private readonly int $output,
        private readonly int $total,
    ) {}

    public static function fromResponse(mixed $response, string $expectedModel): ?self
    {
        if (! $response instanceof CreateResponse) {
            return null;
        }
        try {
            if ($response->model !== $expectedModel) {
                return null;
            }
            $usage = $response->usage;
            if ($usage === null || $usage->inputTokens < 0 || $usage->outputTokens < 0 || $usage->totalTokens < 0
                || $usage->inputTokens > PHP_INT_MAX - $usage->outputTokens
                || $usage->totalTokens !== $usage->inputTokens + $usage->outputTokens) {
                return null;
            }

            return new self($usage->inputTokens, $usage->outputTokens, $usage->totalTokens);
        } catch (Throwable) {
            return null;
        }
    }

    public static function attach(Throwable $failure, ?self $receipt): void
    {
        self::$receipts ??= new WeakMap;
        if ($receipt === null) {
            unset(self::$receipts[$failure]);

            return;
        }
        self::$receipts[$failure] = $receipt;
    }

    public static function take(Throwable $failure): ?array
    {
        $receipt = self::$receipts[$failure] ?? null;
        if ($receipt === null) {
            return null;
        }
        unset(self::$receipts[$failure]);

        return ['input_tokens' => $receipt->input, 'output_tokens' => $receipt->output, 'tokens_used' => $receipt->total,
            'provider_usage_available' => true, 'usage_source' => 'provider_response'];
    }
}
