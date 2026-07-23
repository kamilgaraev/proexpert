<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\Audit\LegalDocumentOutbox;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

final class ReconcileLegalDocumentOutbox extends Command
{
    protected $signature = 'legal-document-outbox:reconcile
        {--organization= : Organization ID}
        {--message= : Outbox message UUID}
        {--retry : Requeue one reconciliation message}
        {--limit=100 : Maximum rows to inspect}';

    protected $description = 'Inspect or safely retry legal document outbox dead letters';

    public function handle(LegalDocumentOutbox $outbox, LoggerInterface $logger): int
    {
        $organizationId = filter_var($this->option('organization'), FILTER_VALIDATE_INT);
        if ($organizationId === false || $organizationId < 1) {
            $this->error(trans_message('legal_archive.commands.organization_required'));

            return self::INVALID;
        }
        $limit = max(1, (int) $this->option('limit'));
        $limit = min($limit, 100);
        $messageId = trim((string) $this->option('message'));

        if ((bool) $this->option('retry')) {
            if ($messageId === '') {
                $this->error(trans_message('legal_archive.commands.message_required'));

                return self::INVALID;
            }
            $requeued = $outbox->retryReconciled($organizationId, $messageId);
            $logger->notice('legal_document_outbox.reconciliation_retry', [
                'organization_id' => $organizationId,
                'message_id' => $messageId,
                'requeued' => $requeued,
            ]);

            return $requeued ? self::SUCCESS : self::FAILURE;
        }

        $candidates = $outbox->reconciliationCandidates($organizationId, $limit);
        $this->table(
            ['message_id', 'event', 'attempts', 'dead_lettered_at', 'last_error'],
            $candidates->map(static fn ($message): array => [
                (string) $message->id,
                (string) $message->event,
                (int) $message->attempts,
                $message->dead_lettered_at?->toAtomString(),
                (string) $message->last_error,
            ])->all(),
        );
        $logger->info('legal_document_outbox.reconciliation_inspected', [
            'organization_id' => $organizationId,
            'count' => $candidates->count(),
            'limit' => $limit,
        ]);

        return self::SUCCESS;
    }
}
