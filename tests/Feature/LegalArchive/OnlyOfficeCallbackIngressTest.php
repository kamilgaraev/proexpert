<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\Http\Middleware\OnlyOfficeCallbackBodyLimit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OnlyOfficeCallbackIngressTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('legal-editor-callback:global');
    }

    public function test_oversized_raw_callback_is_rejected_before_rate_limiter_and_json_decoding(): void
    {
        config()->set('legal-document-editor.callback.max_body_bytes', 64);
        $session = '00000000-0000-4000-8000-000000000001';

        $response = $this->call(
            'POST',
            '/api/v1/legal-document-editor/callback/'.$session,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '65',
                'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('a', 32),
            ],
            str_repeat('{', 65),
        );

        $response->assertStatus(413)->assertExactJson(['error' => 1]);
        self::assertSame(0, RateLimiter::attempts('legal-editor-callback:global'));
    }

    public function test_chunked_callback_body_is_bounded_without_content_length(): void
    {
        config()->set('legal-document-editor.callback.max_body_bytes', 64);
        $session = '00000000-0000-4000-8000-000000000002';

        $response = $this->call(
            'POST',
            '/api/v1/legal-document-editor/callback/'.$session,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
            ],
            str_repeat('[', 65),
        );

        $response->assertStatus(413)->assertExactJson(['error' => 1]);
        self::assertSame(0, RateLimiter::attempts('legal-editor-callback:global'));
    }

    public function test_limiter_identity_never_reads_or_decodes_callback_json(): void
    {
        $provider = file_get_contents(__DIR__.'/../../../app/Providers/RouteServiceProvider.php');
        self::assertIsString($provider);
        self::assertStringNotContainsString('$request->input(\'key\'', $provider);
        self::assertStringContainsString('attributes->get(\'legal_editor_callback_rate_key\'', $provider);

        $routes = file_get_contents(__DIR__.'/../../../routes/api.php');
        self::assertIsString($routes);
        self::assertStringContainsString('OnlyOfficeCallbackBodyLimit::class', $routes);
    }

    public function test_bounded_json_body_remains_readable_downstream(): void
    {
        config()->set('legal-document-editor.callback.max_body_bytes', 1024);
        $body = '{"key":"document-key","status":2}';
        $request = Request::create(
            '/api/v1/legal-document-editor/callback/00000000-0000-4000-8000-000000000004',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $route = new Route('POST', 'api/v1/legal-document-editor/callback/{session}', static fn (): Response => new Response);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        $response = (new OnlyOfficeCallbackBodyLimit)->handle($request, function (Request $bounded) use ($body): Response {
            self::assertSame($body, $bounded->getContent());
            self::assertSame('document-key', $bounded->json('key'));

            return new Response(status: 204);
        });

        self::assertSame(204, $response->getStatusCode());
    }

    public function test_oversized_authorization_header_is_rejected_before_limiter(): void
    {
        $session = '00000000-0000-4000-8000-000000000003';

        $response = $this->call(
            'POST',
            '/api/v1/legal-document-editor/callback/'.$session,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('a', 16385),
            ],
            '{"key":"safe"}',
        );

        $response->assertStatus(413)->assertExactJson(['error' => 1]);
        self::assertSame(0, RateLimiter::attempts('legal-editor-callback:global'));
    }
}
