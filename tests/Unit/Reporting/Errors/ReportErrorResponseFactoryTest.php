<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Errors;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\RenderReportErrors;
use App\Services\Logging\Context\RequestContext;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class ReportErrorResponseFactoryTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->app->setLocale('ru');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->app->flush();

        parent::tearDown();
    }

    public function test_factory_returns_the_exact_stable_admin_error_envelope(): void
    {
        $response = $this->factory()->make(
            ReportContractException::fromCode(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                ['fields' => ['as_of']],
            ),
            'req-report-test-0001',
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'success' => false,
            'message' => 'Некорректно заполнен запрос',
            'data' => null,
            'error' => 'Некорректно заполнен запрос',
            'code' => 'REPORT_REQUEST_INVALID',
            'correlation_id' => 'req-report-test-0001',
            'retryable' => false,
            'details' => ['fields' => ['as_of']],
        ], $response->getData(true));
        self::assertArrayNotHasKey('errors', $response->getData(true));
    }

    public function test_factory_uses_every_catalog_status_and_retryability_without_exception_messages(): void
    {
        foreach (ReportErrorCode::cases() as $code) {
            $exception = ReportContractException::fromCode($code);
            $response = $this->factory()->make($exception, 'req-report-test-0002');
            $body = $response->getData(true);
            $descriptor = ReportErrorCatalog::descriptor($code);

            self::assertSame($descriptor->httpStatus, $response->getStatusCode());
            self::assertSame($code->value, $body['code']);
            self::assertSame($descriptor->retryable, $body['retryable']);
            self::assertSame([], $body['details']);
            self::assertSame('req-report-test-0002', $body['correlation_id']);
            self::assertNotSame($exception->getMessage(), $body['message']);
        }
    }

    public function test_middleware_renders_report_contract_exceptions_without_logging_them(): void
    {
        $logger = new CapturingReportLogger();
        $context = $this->requestContext('req-report-test-0003');
        $middleware = new RenderReportErrors($this->factory(), $context, $logger);

        $response = $middleware->handle(
            Request::create('/api/v1/admin/reports', 'POST'),
            static fn () => throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('REPORT_SORT_UNSUPPORTED', $response->getData(true)['code']);
        self::assertSame([], $logger->records);
    }

    public function test_middleware_maps_unexpected_throwables_and_logs_only_the_safe_context(): void
    {
        $logger = new CapturingReportLogger();
        $context = $this->requestContext('req-report-test-0004');
        $middleware = new RenderReportErrors($this->factory(), $context, $logger);
        $request = Request::create(
            '/api/v1/admin/reports?filters[secret]=bank-account',
            'POST',
            ['organization_id' => 41, 'actor_id' => 73],
        );
        $request->attributes->set('current_organization_id', 41);
        $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 73]));

        $response = $middleware->handle(
            $request,
            static fn () => throw new RuntimeException(
                'SQL failed at https://private.example?filters=bank-account',
            ),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('REPORT_INTERNAL_ERROR', $response->getData(true)['code']);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('report_request_failed', $logger->records[0]['message']);
        self::assertSame([
            'code' => 'REPORT_INTERNAL_ERROR',
            'exception_class' => RuntimeException::class,
            'organization_id' => 41,
            'actor_id' => 73,
            'correlation_id' => 'req-report-test-0004',
        ], $logger->records[0]['context']);
        self::assertStringNotContainsString('bank-account', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private.example', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SQL failed', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    private function factory(): ReportErrorResponseFactory
    {
        return new ReportErrorResponseFactory(new ReportErrorCatalog());
    }

    private function requestContext(string $correlationId): RequestContext
    {
        $context = new RequestContext();
        $context->setCorrelationId($correlationId);

        return $context;
    }
}

final class CapturingReportLogger extends AbstractLogger
{
    public array $records = [];

    public function log(
        mixed $level,
        string|Stringable $message,
        array $context = [],
    ): void {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
