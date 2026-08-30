<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Estimate;

use App\Http\Requests\Estimate\ValidateEstimateContractAmountRequest;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 5).'/app/Http/Requests/Estimate/ValidateEstimateContractAmountRequest.php';

final class ValidateEstimateContractAmountRequestTest extends TestCase
{
    public function test_it_normalizes_boolean_query_strings_before_validation(): void
    {
        self::assertFalse($this->validateIncludeVat('false'));
        self::assertTrue($this->validateIncludeVat('true'));
    }

    public function test_it_does_not_accept_an_unknown_boolean_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->validateIncludeVat('not-a-boolean');
    }

    private function validateIncludeVat(string $value): bool
    {
        $baseRequest = Request::create(
            '/projects/52/estimates/422/contract/validation',
            'GET',
            ['contract_id' => '261', 'include_vat' => $value]
        );

        $request = ValidateEstimateContractAmountRequest::createFromBase($baseRequest);
        $prepareForValidation = new ReflectionMethod($request, 'prepareForValidation');
        $prepareForValidation->invoke($request);

        $validator = new Factory(new Translator(new ArrayLoader, 'ru'));
        $validated = $validator->validate($request->all(), $request->rules());

        return (bool) $validated['include_vat'];
    }
}
