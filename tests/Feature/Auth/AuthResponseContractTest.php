<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthResponseContractTest extends TestCase
{
    #[DataProvider('responseClasses')]
    public function test_auth_error_responses_share_safe_machine_readable_fields(string $responseClass): void
    {
        $response = $responseClass::error(
            trans_message('auth.access_denied'),
            403,
            ['email' => ['Проверьте значение.']],
            ['code' => 'auth_forbidden'],
        );
        $payload = $response->getData(true);

        self::assertFalse($payload['success']);
        self::assertNull($payload['data']);
        self::assertSame(trans_message('auth.access_denied'), $payload['message']);
        self::assertSame('auth_forbidden', $payload['code']);
        self::assertSame(['email' => ['Проверьте значение.']], $payload['errors']);
        self::assertStringNotContainsString('Exception', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_auth_errors_receive_a_stable_fallback_code(): void
    {
        self::assertSame('http_401', LandingResponse::error('safe', 401)->getData(true)['code']);
        self::assertSame('http_429', CustomerResponse::error('safe', 429)->getData(true)['code']);
        self::assertSame('http_500', AdminResponse::error('safe', 500)->getData(true)['code']);
        self::assertSame('http_422', MobileResponse::error('safe', 422)->getData(true)['code']);
    }

    public function test_registration_validation_messages_are_resolved_from_translations(): void
    {
        $messages = (new RegisterRequest())->messages();

        self::assertSame(trans_message('auth.validation.name_required'), $messages['name.required']);
        self::assertSame(trans_message('auth.validation.phone_invalid'), $messages['phone.regex']);
        self::assertSame(
            trans_message('auth.validation.organization_tax_number_invalid'),
            $messages['organization_tax_number.regex'],
        );
        self::assertSame(trans_message('auth.validation.privacy_required'), $messages['privacy_accepted.accepted']);
        self::assertSame(
            trans_message('auth.validation.idempotency_key_invalid'),
            $messages['idempotency_key.*'],
        );
    }

    public static function responseClasses(): iterable
    {
        yield 'landing' => [LandingResponse::class];
        yield 'admin' => [AdminResponse::class];
        yield 'customer' => [CustomerResponse::class];
        yield 'mobile' => [MobileResponse::class];
    }
}
