<?php

declare(strict_types=1);

namespace App\Jobs\LegalArchive;

use App\Services\LegalArchive\Audit\LegalDocumentOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

final class MonitorLegalDocumentOutboxDeadLetters implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $organizationLimit = 100)
    {
        $this->onQueue('legal-document-outbox');
    }

    public function handle(LegalDocumentOutbox $outbox, LoggerInterface $logger): void
    {
        foreach ($outbox->reconciliationCounts($this->organizationLimit) as $row) {
            $logger->warning('legal_document_outbox.reconciliation_required', [
                'organization_id' => (int) $row->organization_id,
                'messages_count' => (int) $row->messages_count,
                'oldest_at' => $row->oldest_at,
            ]);
        }
    }
}
