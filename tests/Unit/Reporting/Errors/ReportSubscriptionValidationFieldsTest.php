<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Errors;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ListReportSubscriptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportFormRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReportSubscriptionValidationFieldsTest extends TestCase
{
    public function test_missing_list_query_fields_produce_a_safe_validation_error(): void
    {
        $request = new ListReportSubscriptionsRequest;
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $validator = $factory->make([], $request->rules());
        self::assertTrue($validator->fails());
        try {
            (new ReflectionMethod(ReportFormRequest::class, 'failedValidation'))->invoke($request, $validator);
            self::fail('Invalid list request must fail validation');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame(['fields' => ['cursor', 'limit', 'status']], $exception->safeFields);
        }
    }
}
