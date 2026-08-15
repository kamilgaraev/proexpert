<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class TimewebProjectSynthesisModel implements ProjectSynthesisModel
{
    public function __construct(
        private RerankWireClient $wire,
        private AiUsageStore $usage,
        private AiPriceSnapshotResolver $prices,
        private string $modelName,
        private int $maxInputBytes,
        private int $maxOutputTokens,
        private int $timeoutSeconds,
        private ?DurableAiPhysicalResponseStore $responses = null,
    ) {
        if (preg_match('#^[A-Za-z0-9._/-]{1,160}$#D', $modelName) !== 1
            || $maxInputBytes < 1 || $maxOutputTokens < 1 || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('project_synthesis_model_configuration_invalid');
        }
    }

    public function synthesize(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
        callable $onPhysicalAttemptReserved,
    ): array {
        $payload = json_encode([
            'project' => $input->canonicalPayload(),
            'candidate_links' => $candidateLinks,
            'candidate_questions' => $candidateQuestions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($payload) > $this->maxInputBytes) {
            throw new InvalidArgumentException('project_synthesis_input_limit_exceeded');
        }
        $attemptId = AiOperationContext::deterministicId('project-synthesis-attempt|'.$input->fingerprint());
        $onPhysicalAttemptReserved($attemptId);
        $context = new AiOperationContext(
            AiOperationContext::deterministicId('project-synthesis|'.$input->fingerprint()),
            $attemptId,
            $input->organizationId,
            $input->projectId,
            $input->sessionId,
            'understand_documents',
            'project_synthesis',
            1,
        );
        $price = $this->prices->resolve($context, $this->wire->provider(), $this->modelName);
        $response = [];
        $status = 'connection_failed';
        $httpCode = null;
        $started = hrtime(true);
        $usageRecorded = false;
        try {
            $replay = $this->responses?->replay($attemptId, $input->fingerprint());
            if ($replay !== null) {
                $response = $replay['provider_response'];
                $usageRecorded = $replay['usage_recorded'];
                $status = 'succeeded';

                return $replay['parsed_response'];
            }
            $response = $this->wire->call($this->modelName, [
                ['role' => 'system', 'content' => $this->prompt()],
                ['role' => 'user', 'content' => $payload],
            ], [
                'profile' => 'json',
                'temperature' => 0,
                'max_tokens' => $this->maxOutputTokens,
                'timeout' => $this->timeoutSeconds,
            ]);
            $decoded = json_decode($this->normalizedContent((string) ($response['content'] ?? '')), true);
            if (! is_array($decoded) || ($response['model'] ?? null) !== $this->modelName) {
                throw new RerankWireException('malformed_response');
            }
            $status = 'succeeded';
            $this->responses?->store(
                $attemptId,
                $input->fingerprint(),
                $decoded,
                $response,
                (int) max(0, round((hrtime(true) - $started) / 1_000_000)),
                $price->toArray(),
            );

            return $decoded;
        } catch (RerankWireException $exception) {
            $status = $exception->attemptStatus;
            $httpCode = $exception->httpCode;
            throw $exception;
        } finally {
            if (! $usageRecorded) {
                $this->record($context, $status, $httpCode, $response, $started, $price);
                if ($status === 'succeeded') {
                    $this->responses?->markUsageRecorded($attemptId, $input->fingerprint());
                }
            }
        }
    }

    private function prompt(): string
    {
        return 'Ты инженер единой модели строительного проекта. Сопоставь факты разных документов, геометрические производные и решения оператора. '
            .'Не вычисляй стоимость и не изобретай факты, ссылки или идентификаторы. Учитывай материал кровли из общих данных, площадь из плана кровли '
            .'и визуальное подтверждение совместно; не объединяй повтор одного физического проёма; условные конструкции оставляй вопросом. '
            .'Верни только JSON ровно с ключами accepted_link_ids и question_conflict_ids. Значения — массивы только существующих идентификаторов кандидатов. '
            .'Сервер сам добавит обязательные confirmed-связи и вопросы по всем известным конфликтам; не повторяй их ради полноты.';
    }

    private function normalizedContent(string $content): string
    {
        $content = trim($content);
        if (is_array(json_decode($content, true))) {
            return $content;
        }
        if (preg_match('/\A```(?:json)?[ \t]*\R(?<json>[\s\S]+)\R```[ \t]*\z/i', $content, $matches) !== 1) {
            return $content;
        }

        return trim((string) ($matches['json'] ?? ''));
    }

    /** @param array<string, mixed> $response */
    private function record(
        AiOperationContext $context,
        string $status,
        ?int $httpCode,
        array $response,
        int $started,
        AiPriceSnapshot $price,
    ): void {
        try {
            $measured = ($response['usage_available'] ?? false) === true;
            $this->usage->record(new AiUsageData(
                context: $context,
                provider: $this->wire->provider(),
                requestedModel: $this->modelName,
                reportedModel: is_string($response['model'] ?? null) ? $response['model'] : null,
                status: in_array($status, ['succeeded', 'http_failed', 'connection_failed', 'malformed_response'], true)
                    ? $status
                    : 'connection_failed',
                durationMs: (int) max(0, round((hrtime(true) - $started) / 1_000_000)),
                usageStatus: $measured ? 'measured' : 'unavailable',
                inputTokens: $measured ? max(0, (int) ($response['input_tokens'] ?? 0)) : 0,
                outputTokens: $measured ? max(0, (int) ($response['output_tokens'] ?? 0)) : 0,
                httpCode: $httpCode,
                priceSnapshot: $price,
            ));
        } catch (Throwable $exception) {
            try {
                Log::error('[EstimateGeneration] Project synthesis usage recording failed', [
                    'attempt_id' => $context->attemptId,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            throw new RuntimeException('usage_recording_failed', previous: $exception);
        }
    }
}
