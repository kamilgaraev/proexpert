<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Notifications\LegalArchive\LegalDocumentApprovalRequiredNotification;
use PHPUnit\Framework\TestCase;

final class LegalDocumentNotificationIdempotencyTest extends TestCase
{
    public function test_notification_has_stable_document_route_and_type(): void
    {
        $document = new LegalArchiveDocument(['title' => 'Договор']);
        $document->setAttribute('id', 42);

        $first = (new LegalDocumentApprovalRequiredNotification($document))->toArray(new \stdClass());
        $second = (new LegalDocumentApprovalRequiredNotification($document))->toArray(new \stdClass());

        self::assertSame($first, $second);
        self::assertSame('legal_document_approval_required', $first['type']);
        self::assertSame('/legal-archive/documents/42', $first['targetRoute']);
    }
}
