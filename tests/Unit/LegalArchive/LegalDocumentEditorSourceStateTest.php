<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentEditorSession;
use App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService;
use PHPUnit\Framework\TestCase;

final class LegalDocumentEditorSourceStateTest extends TestCase
{
    public function test_it_distinguishes_a_replaced_source_pointer_from_a_version_owned_by_the_same_editor_session(): void
    {
        $service = (new \ReflectionClass(LegalDocumentEditorSessionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'sourceStateFailureCode');
        $session = new LegalDocumentEditorSession;
        $session->forceFill(['id' => 'session-1', 'source_version_id' => 10, 'source_content_hash' => str_repeat('a', 64)]);
        $document = new LegalArchiveDocument;
        $document->forceFill(['current_primary_version_id' => 11]);
        $current = new LegalArchiveDocumentVersion;
        $current->forceFill(['metadata' => ['editor_session_id' => 'other-session']]);

        self::assertSame(
            'legal_document_editor_source_pointer_changed',
            $method->invoke($service, $session, $document, null, $current),
        );

        $current->forceFill(['metadata' => ['editor_session_id' => 'session-1']]);

        self::assertNull($method->invoke($service, $session, $document, null, $current));
    }
}
