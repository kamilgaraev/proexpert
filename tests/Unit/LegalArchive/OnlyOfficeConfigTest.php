<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Editor\EditorDocumentContext;
use App\Services\LegalArchive\Editor\OnlyOfficeDocumentEditor;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class OnlyOfficeConfigTest extends TestCase
{
    public function test_configuration_is_signed_and_document_key_is_content_bound(): void
    {
        $editor = new OnlyOfficeDocumentEditor([
            'enabled' => true,
            'url' => 'https://office.example.test',
            'jwt_secret' => str_repeat('s', 48),
        ]);
        $context = new EditorDocumentContext(
            'session-id', 10, 20, 30, 40, 50, 3,
            str_repeat('a', 64), 'contract.docx',
            'https://api.example.test/source', 'https://api.example.test/callback',
            new DateTimeImmutable('+5 minutes'),
        );

        $payload = $editor->createSession($context, 'Иван Иванов');

        self::assertTrue($payload->enabled);
        self::assertSame('edit', $payload->mode);
        self::assertSame('30.', $payload->documentKeyPrefix);
        self::assertNotEmpty($payload->token);
        self::assertSame($payload->documentKey, $payload->configuration['document']['key']);
        self::assertSame($payload->token, $payload->configuration['token']);
        self::assertSame('ru', $payload->configuration['editorConfig']['lang']);
        $claims = \App\Services\LegalArchive\Editor\OnlyOfficeJwt::decode($payload->token, str_repeat('s', 48));
        self::assertSame($payload->documentKey, $claims['document']['key']);
        self::assertSame('https://api.example.test/callback', $claims['editorConfig']['callbackUrl']);
        self::assertSame('ru', $claims['editorConfig']['lang']);

        $this->expectException(DomainException::class);
        $editor->verifyCallbackToken($payload->token, new \App\Services\LegalArchive\Editor\EditorCallbackInput(
            'session-id', $payload->documentKey, 2, 'https://office.example.test/result.docx', 'replay', $payload->token,
        ));
    }

    public function test_editor_session_lifetime_is_not_clamped_to_source_url_ttl(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('sourceUrlMinutes', $source);
        self::assertStringContainsString("session_ttl_minutes', 120", $source);
        self::assertStringContainsString('source_url_ttl_minutes', $source);
    }

    public function test_transport_disables_proxy_and_checks_connected_ip(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/OnlyOfficeBoundedDocumentFetcher.php');
        self::assertIsString($source);
        self::assertStringContainsString("'no_proxy' => '*'", $source);
        self::assertStringNotContainsString("'proxy' => ''", $source);
        self::assertStringContainsString('new CurlHttpClient', $source);
        self::assertStringContainsString("'primary_ip'", $source);
    }

    public function test_callback_token_rejects_body_drift(): void
    {
        $editor = new OnlyOfficeDocumentEditor([
            'enabled' => true, 'url' => 'https://office.example.test', 'jwt_secret' => str_repeat('s', 48),
        ]);
        $token = \App\Services\LegalArchive\Editor\OnlyOfficeJwt::encode([
            'key' => 'document-key', 'status' => 2, 'url' => 'https://office.example.test/result.docx', 'exp' => time() + 60,
        ], str_repeat('s', 48));
        $this->expectException(DomainException::class);
        $editor->verifyCallbackToken($token, new \App\Services\LegalArchive\Editor\EditorCallbackInput(
            'session', 'document-key', 6, 'https://office.example.test/result.docx', 'replay', $token,
        ));
    }

    public function test_callback_token_accepts_onlyoffice_outbox_payload_claims(): void
    {
        $editor = new OnlyOfficeDocumentEditor([
            'enabled' => true, 'url' => 'https://office.example.test', 'jwt_secret' => str_repeat('s', 48),
        ]);
        $token = \App\Services\LegalArchive\Editor\OnlyOfficeJwt::encode([
            'payload' => [
                'key' => 'document-key',
                'status' => 2,
                'url' => 'https://office.example.test/result.docx',
            ],
            'exp' => time() + 60,
        ], str_repeat('s', 48));

        $editor->verifyCallbackToken($token, new \App\Services\LegalArchive\Editor\EditorCallbackInput(
            'session', 'document-key', 2, 'https://office.example.test/result.docx', 'replay', $token,
        ));

        self::assertTrue(true);
    }

    public function test_view_and_review_modes_have_server_enforced_permissions(): void
    {
        $editor = new OnlyOfficeDocumentEditor([
            'enabled' => true,
            'url' => 'https://office.example.test',
            'jwt_secret' => str_repeat('s', 48),
        ]);
        $base = ['session', 10, 20, 30, 40, 50, 3, str_repeat('a', 64), 'contract.docx',
            'https://api.example.test/source', '', new DateTimeImmutable('+2 hours')];
        $view = $editor->createSession(new EditorDocumentContext(...[...$base, 'view']), 'Иван Иванов');
        self::assertSame('view', $view->mode);
        self::assertFalse($view->configuration['document']['permissions']['edit']);
        self::assertFalse($view->configuration['document']['permissions']['review']);
        self::assertFalse($view->configuration['document']['permissions']['comment']);
        self::assertArrayNotHasKey('callbackUrl', $view->configuration['editorConfig']);
        self::assertSame('ru', $view->configuration['editorConfig']['lang']);
        self::assertSame(
            'ru',
            \App\Services\LegalArchive\Editor\OnlyOfficeJwt::decode($view->token, str_repeat('s', 48))['editorConfig']['lang'],
        );

        $review = $editor->createSession(new EditorDocumentContext(...[...$base, 'review']), 'Иван Иванов');
        self::assertSame('review', $review->mode);
        self::assertFalse($review->configuration['document']['permissions']['edit']);
        self::assertTrue($review->configuration['document']['permissions']['review']);
        self::assertTrue($review->configuration['document']['permissions']['comment']);
        self::assertSame('ru', $review->configuration['editorConfig']['lang']);
        self::assertSame(
            'ru',
            \App\Services\LegalArchive\Editor\OnlyOfficeJwt::decode($review->token, str_repeat('s', 48))['editorConfig']['lang'],
        );
    }

    public function test_invalid_or_incomplete_server_configuration_fails_closed(): void
    {
        $this->expectException(DomainException::class);
        new OnlyOfficeDocumentEditor(['enabled' => true, 'url' => 'http://office.test', 'jwt_secret' => 'short']);
    }

    public function test_schema_is_phased_forward_only_and_exact(): void
    {
        $root = __DIR__.'/../../../';
        foreach (['000700_create_legal_document_editor_sessions', '000710_create_legal_document_editor_session_indexes',
            '000720_add_legal_document_editor_session_constraints', '000730_validate_legal_document_editor_session_constraints'] as $name) {
            $source = file_get_contents($root.'database/migrations/2026_07_19_'.$name.'.php');
            self::assertIsString($source);
            self::assertStringContainsString('forward_only', $source);
        }
        $indexes = file_get_contents($root.'database/migrations/2026_07_19_000710_create_legal_document_editor_session_indexes.php');
        self::assertIsString($indexes);
        self::assertStringContainsString('pg_get_indexdef', $indexes);
        self::assertStringContainsString('indnkeyatts', $indexes);
        self::assertStringContainsString('indnullsnotdistinct', $indexes);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $indexes);
    }
}
