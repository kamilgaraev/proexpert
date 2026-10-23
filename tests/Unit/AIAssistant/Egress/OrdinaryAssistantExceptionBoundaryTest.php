<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantProviderFailure;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantExceptionBoundary;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;
use Throwable;

final class OrdinaryAssistantExceptionBoundaryTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const CANARY = 'offline source fragment canary';

    #[DataProvider('domainFailures')]
    public function test_semantic_classes_are_recreated_without_raw_arguments_or_previous(string $kind, string $class): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $original = $this->failure($kind, self::CANARY);
            self::assertNotEmpty($original->getTrace()[0]['args']);
            $safe = OrdinaryAssistantExceptionBoundary::sanitize($original);
            self::assertInstanceOf($class, $safe);
            self::assertNotSame($original, $safe);
            self::assertNull($safe->getPrevious());
            foreach ($safe->getTrace() as $frame) {
                self::assertArrayNotHasKey('args', $frame, ($frame['class'] ?? '').'::'.$frame['function']);
            }
            self::assertFalse(str_contains(json_encode($safe->getTrace(), JSON_THROW_ON_ERROR), self::CANARY));
            self::assertFalse(str_contains((string) $safe, self::CANARY));
            self::assertFalse(str_contains($safe->getTraceAsString(), self::CANARY));
            self::assertFalse(str_contains(json_encode(get_object_vars($safe), JSON_THROW_ON_ERROR), self::CANARY));
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
            if ($safe instanceof AssistantEgressDenied) {
                self::assertSame('source_stale', $safe->reason);
            }
            if ($safe instanceof AssistantResponseIncomplete) {
                self::assertSame('content_filter', $safe->reason);
                self::assertSame('ai_assistant.response_incomplete', $safe->messageKey());
                self::assertSame(['input_tokens' => 71, 'output_tokens' => 9, 'tokens_used' => 80], $safe->providerUsage);
            }
            if ($safe instanceof AuthorizationException) {
                self::assertSame(404, $safe->status());
                self::assertNull($safe->response());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function domainFailures(): array
    {
        return [
            'denial' => ['denial', AssistantEgressDenied::class],
            'cancelled' => ['cancelled', AssistantRequestCancelled::class],
            'deadline-with-previous' => ['deadline', AssistantRequestDeadlineExceeded::class],
            'budget' => ['budget', AssistantBudgetExceeded::class],
            'incomplete-with-raw-response' => ['incomplete', AssistantResponseIncomplete::class],
            'authorization-with-previous' => ['authorization', AuthorizationException::class],
            'invalid-request' => ['invalid', InvalidArgumentException::class],
            'runtime-with-previous' => ['runtime', RuntimeException::class],
            'unknown-with-previous' => ['unknown', AssistantProviderFailure::class],
        ];
    }

    public function test_unavailable_ini_set_falls_back_to_a_safe_exception_without_trace_on_php_85(): void
    {
        $autoload = var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true);
        $code = 'require '.$autoload.'; function privateCaller(string $private): Throwable { return \\App\\BusinessModules\\Features\\AIAssistant\\Services\\Egress\\OrdinaryAssistantExceptionBoundary::sanitize(new RuntimeException($private, 0, new RuntimeException($private))); }'
            .' $safe = privateCaller("offline source fragment canary"); echo json_encode(["class" => $safe::class, "message" => $safe->getMessage(), "trace" => $safe->getTrace(), "previous" => $safe->getPrevious(), "setting" => ini_get("zend.exception_ignore_args")], JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=ini_set', '-d', 'zend.exception_ignore_args=0', '-r', $code]);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        self::assertSame(['class' => RuntimeException::class, 'message' => 'assistant_provider_failed',
            'trace' => [], 'previous' => null, 'setting' => '0'], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function failure(string $kind, string $private): Throwable
    {
        return match ($kind) {
            'denial' => new AssistantEgressDenied('source_stale'),
            'cancelled' => new AssistantRequestCancelled,
            'deadline' => new AssistantRequestDeadlineExceeded(new RuntimeException($private)),
            'budget' => new AssistantBudgetExceeded,
            'incomplete' => new AssistantResponseIncomplete(['incomplete_reason' => 'content_filter', 'input_tokens' => 71,
                'output_tokens' => 9, 'tokens_used' => 80, 'profile' => $private, 'model' => $private,
                'token_calibration' => ['raw' => $private], 'output' => [['text' => $private]]]),
            'authorization' => (new AuthorizationException($private, 0, new RuntimeException($private)))->asNotFound(),
            'invalid' => new InvalidArgumentException($private),
            'runtime' => new RuntimeException($private, 0, new RuntimeException($private)),
            default => new \Exception($private, 0, new RuntimeException($private)),
        };
    }
}
