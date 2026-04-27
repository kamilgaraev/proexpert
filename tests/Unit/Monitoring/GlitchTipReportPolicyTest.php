<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Exceptions\AI\AIAuthenticationException;
use App\Exceptions\AI\AIParsingException;
use App\Exceptions\AI\AIQuotaExceededException;
use App\Exceptions\Billing\InsufficientBalanceException;
use App\Exceptions\BusinessLogicException;
use App\Services\Monitoring\GlitchTipReportPolicy;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class GlitchTipReportPolicyTest extends TestCase
{
    #[DataProvider('reportableExceptions')]
    public function test_captures_actionable_exceptions(\Closure $exceptionFactory, string $level): void
    {
        $policy = new GlitchTipReportPolicy($this->config());
        $exception = $exceptionFactory();

        self::assertTrue($policy->shouldCapture($exception));
        self::assertSame($level, $policy->levelFor($exception));
    }

    #[DataProvider('ignoredExceptions')]
    public function test_skips_expected_exceptions(\Closure $exceptionFactory): void
    {
        $policy = new GlitchTipReportPolicy($this->config());
        $exception = $exceptionFactory();

        self::assertFalse($policy->shouldCapture($exception));
    }

    public function test_captures_business_exceptions_only_when_enabled(): void
    {
        $policy = new GlitchTipReportPolicy($this->config([
            'business_logic' => [
                'capture' => true,
                'level' => 'warning',
            ],
        ]));

        self::assertTrue($policy->shouldCapture(new BusinessLogicException('workflow mismatch', 409)));
        self::assertSame('warning', $policy->levelFor(new BusinessLogicException('workflow mismatch', 409)));
    }

    public static function reportableExceptions(): array
    {
        return [
            'unexpected runtime' => [fn () => new \RuntimeException('unexpected'), 'error'],
            'database query' => [fn () => new QueryException('pgsql', 'select 1', [], new PDOException('db failed')), 'fatal'],
            'pdo' => [fn () => new PDOException('connection failed'), 'fatal'],
            'configuration' => [fn () => new \InvalidArgumentException('bad config'), 'fatal'],
            'post too large' => [fn () => new PostTooLargeException(), 'warning'],
            'ai auth' => [fn () => new AIAuthenticationException('bad key'), 'fatal'],
            'ai parsing' => [fn () => new AIParsingException('bad response'), 'error'],
        ];
    }

    public static function ignoredExceptions(): array
    {
        return [
            'validation' => [fn () => self::validationException()],
            'authentication' => [fn () => new AuthenticationException()],
            'authorization' => [fn () => new AuthorizationException()],
            'not found' => [fn () => new NotFoundHttpException()],
            'rate limit' => [fn () => new TooManyRequestsHttpException()],
            'balance' => [fn () => new InsufficientBalanceException('not enough')],
            'business logic by default' => [fn () => new BusinessLogicException('expected workflow guard', 409)],
            'ai quota' => [fn () => new AIQuotaExceededException('quota')],
        ];
    }

    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'default_level' => 'error',
            'capture' => [
                'exceptions' => [
                    QueryException::class => 'fatal',
                    PDOException::class => 'fatal',
                    \InvalidArgumentException::class => 'fatal',
                    PostTooLargeException::class => 'warning',
                    AIAuthenticationException::class => 'fatal',
                    AIParsingException::class => 'error',
                ],
                'http_statuses' => [
                    500 => 'error',
                    503 => 'error',
                ],
            ],
            'ignore' => [
                'exceptions' => [
                    ValidationException::class,
                    AuthenticationException::class,
                    AuthorizationException::class,
                    NotFoundHttpException::class,
                    TooManyRequestsHttpException::class,
                    InsufficientBalanceException::class,
                    AIQuotaExceededException::class,
                ],
                'http_statuses' => [
                    401,
                    403,
                    404,
                    429,
                ],
            ],
            'business_logic' => [
                'capture' => false,
                'level' => 'warning',
            ],
        ], $overrides);
    }

    private static function validationException(): ValidationException
    {
        $validator = new class implements Validator {
            public function validate()
            {
                return [];
            }

            public function validated()
            {
                return [];
            }

            public function fails()
            {
                return true;
            }

            public function failed()
            {
                return [];
            }

            public function sometimes($attribute, $rules, callable $callback)
            {
                return $this;
            }

            public function after($callback)
            {
                return $this;
            }

            public function errors()
            {
                return new MessageBag(['name' => ['required']]);
            }

            public function getMessageBag()
            {
                return $this->errors();
            }

            public function getTranslator(): object
            {
                return new class {
                    public function get(string $key): string
                    {
                        return $key;
                    }

                    public function choice(string $key, int $number, array $replace = []): string
                    {
                        return strtr($key, [':count' => (string) $number]);
                    }
                };
            }
        };

        return new ValidationException($validator);
    }
}
