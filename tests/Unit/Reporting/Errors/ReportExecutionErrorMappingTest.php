<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Errors;

require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Errors/ReportErrorCatalog.php';
require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Errors/ReportContractException.php';
require_once dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Errors/ReportErrorResponseFactory.php';

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ReportExecutionErrorMappingTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $basePath = dirname(__DIR__, 4);
        $this->app = new Application($basePath);
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
        ]));
        $loader = new ArrayLoader;
        $loader->addMessages('ru', 'reports', require $basePath.'/lang/ru/reports.php');
        $this->app->instance('translator', new Translator($loader, 'ru'));
        $this->app->setLocale('ru');
        $this->app->instance('log', new NullLogger);
        $this->app->instance(ResponseFactory::class, new class
        {
            public function json(array $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });
    }

    public function test_every_error_has_a_safe_translation_and_complete_retryability_mapping(): void
    {
        $retryable = [
            ReportErrorCode::REPORT_SNAPSHOT_NOT_READY->value,
            ReportErrorCode::REPORT_EXPORT_NOT_READY->value,
            ReportErrorCode::REPORT_RATE_LIMITED->value,
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE->value,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED->value,
            ReportErrorCode::REPORT_INTERNAL_ERROR->value,
        ];

        foreach (ReportErrorCode::cases() as $code) {
            $descriptor = ReportErrorCatalog::descriptor($code);
            self::assertSame(in_array($code->value, $retryable, true), $descriptor->retryable);
            $message = trans_message($descriptor->translationKey);
            self::assertIsString($message);
            self::assertNotSame('', trim($message));
            self::assertNotSame($descriptor->translationKey, $message);
        }
    }

    public function test_technical_exception_text_never_reaches_admin_response(): void
    {
        $technical = 'SQLSTATE secret table detail';
        $exception = ReportContractException::fromCode(
            ReportErrorCode::REPORT_INTERNAL_ERROR,
            previous: new RuntimeException($technical),
        );

        $response = (new ReportErrorResponseFactory(new ReportErrorCatalog))->make($exception, 'report-correlation');
        self::assertStringNotContainsString($technical, (string) $response->getContent());
        self::assertStringContainsString(ReportErrorCode::REPORT_INTERNAL_ERROR->value, (string) $response->getContent());
        self::assertStringContainsString('report-correlation', (string) $response->getContent());
    }
}
