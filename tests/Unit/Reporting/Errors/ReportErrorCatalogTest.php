<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Errors;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorDescriptor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;
use RuntimeException;

final class ReportErrorCatalogTest extends TestCase
{
    #[Test]
    public function every_error_code_has_the_exact_http_and_retryability_descriptor(): void
    {
        foreach ([
            ReportErrorCode::REPORT_NOT_FOUND->value => [404, false],
            ReportErrorCode::REPORT_SCOPE_FORBIDDEN->value => [403, false],
            ReportErrorCode::REPORT_REQUEST_INVALID->value => [422, false],
            ReportErrorCode::REPORT_FILTER_UNSUPPORTED->value => [422, false],
            ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND->value => [422, false],
            ReportErrorCode::REPORT_FILTER_RANGE_INVALID->value => [422, false],
            ReportErrorCode::REPORT_SORT_UNSUPPORTED->value => [422, false],
            ReportErrorCode::REPORT_CURSOR_INVALID->value => [422, false],
            ReportErrorCode::REPORT_IDEMPOTENCY_KEY_INVALID->value => [422, false],
            ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT->value => [409, false],
            ReportErrorCode::REPORT_SNAPSHOT_NOT_READY->value => [409, true],
            ReportErrorCode::REPORT_EXPORT_NOT_READY->value => [409, true],
            ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED->value => [409, false],
            ReportErrorCode::REPORT_SNAPSHOT_EXPIRED->value => [410, false],
            ReportErrorCode::REPORT_EXPORT_EXPIRED->value => [410, false],
            ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED->value => [413, false],
            ReportErrorCode::REPORT_RATE_LIMITED->value => [429, true],
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE->value => [503, true],
            ReportErrorCode::REPORT_DEPENDENCY_FAILED->value => [503, true],
            ReportErrorCode::REPORT_INTERNAL_ERROR->value => [500, true],
        ] as $codeValue => [$httpStatus, $retryable]) {
            $descriptor = ReportErrorCatalog::descriptor(ReportErrorCode::from($codeValue));

            self::assertSame($httpStatus, $descriptor->httpStatus);
            self::assertSame($retryable, $descriptor->retryable);
        }
    }

    #[Test]
    public function reflection_proves_the_catalog_has_no_missing_or_duplicate_error_descriptors(): void
    {
        $cases = (new ReflectionEnum(ReportErrorCode::class))->getCases();
        $descriptors = array_map(
            static fn (ReportErrorCode $code): ReportErrorDescriptor => ReportErrorCatalog::descriptor($code),
            array_map(static fn ($case): ReportErrorCode => $case->getValue(), $cases),
        );

        self::assertSame(
            array_map(static fn ($case): string => $case->getValue()->value, $cases),
            array_map(static fn (ReportErrorDescriptor $descriptor): string => $descriptor->code->value, $descriptors),
        );
    }

    #[Test]
    public function descriptor_rejects_invalid_http_statuses_and_translation_keys(): void
    {
        foreach ([
            [399, 'reports.errors.report_not_found'],
            [404, 'report.errors.report_not_found'],
            [404, 'reports.errors.'],
        ] as [$httpStatus, $translationKey]) {
            try {
                new ReportErrorDescriptor(ReportErrorCode::REPORT_NOT_FOUND, $httpStatus, false, $translationKey);
                self::fail('Недопустимый дескриптор ошибки был принят.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function contract_exception_preserves_safe_fields_and_previous_exception(): void
    {
        $previous = new RuntimeException('internal');
        $exception = ReportContractException::fromCode(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            ['fields' => ['as_of']],
            $previous,
        );

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame(['fields' => ['as_of']], $exception->safeFields);
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function contract_exception_accepts_a_single_allowlisted_safe_field(): void
    {
        $exception = ReportContractException::fromCode(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            ['fields' => 'as_of'],
        );

        self::assertSame(['fields' => 'as_of'], $exception->safeFields);
    }

    #[Test]
    public function contract_exception_rejects_non_allowlisted_safe_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportContractException(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            ['url' => 'https://private'],
        );
    }

    #[Test]
    public function contract_exception_rejects_values_outside_the_closed_safe_field_allowlist(): void
    {
        foreach ([
            ['fields' => null],
            ['fields' => []],
            ['fields' => ['passport_number']],
            ['fields' => ['select * from projects']],
            ['fields' => ['filter.status']],
            ['fields' => ['raw validation message']],
            ['fields' => ['field' => 'as_of']],
            ['fields' => [['as_of']]],
        ] as $safeFields) {
            try {
                new ReportContractException(ReportErrorCode::REPORT_REQUEST_INVALID, $safeFields);
                self::fail('Недопустимое безопасное поле было принято.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
