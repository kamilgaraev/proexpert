<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\Editor\EditorSessionPayload;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LegalDocumentEditorResponseContractTest extends TestCase
{
    public function test_blank_editor_session_uses_the_documented_snake_case_contract(): void
    {
        $session = new EditorSessionPayload(
            true,
            'edit',
            'document-key',
            'document-key-prefix',
            'https://office.example.test',
            'token',
            [],
            new DateTimeImmutable('2026-07-21T12:00:00+00:00'),
        );

        $method = new ReflectionMethod(AdminResponse::class, 'transformData');
        $payload = $method->invoke(null, ['editor_session' => $session]);

        self::assertSame('https://office.example.test', $payload['editor_session']['server_url']);
        self::assertArrayNotHasKey('serverUrl', $payload['editor_session']);
    }
}
