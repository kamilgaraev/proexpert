<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalSignatureExpiryService
{
    public function __construct(
        private ConnectionInterface $connection,
        private LegalDocumentAudit $audit,
        private LegalSignatureProjection $projection,
        private LegalDocumentAggregateLock $aggregateLock = new LegalDocumentAggregateLock,
    ) {}

    public function expireDue(int $limit = 100): int
    {
        $ids = $this->connection->table('legal_signature_requests')
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += $this->expireOne((int) $id) ? 1 : 0;
        }

        return $expired;
    }

    private function expireOne(int $requestId): bool
    {
        return $this->connection->transaction(function () use ($requestId): bool {
            $request = (new LegalSignatureRequest)->setConnection($this->connection->getName())
                ->newQuery()->whereKey($requestId)->lockForUpdate()->first();
            if (! $request instanceof LegalSignatureRequest || $request->status !== 'pending'
                || $request->expires_at === null || $request->expires_at->isFuture()) {
                return false;
            }
            $document = $this->aggregateLock->lockDocument(
                $this->connection,
                (int) $request->organization_id,
                (int) $request->document_id,
            );
            $this->aggregateLock->lockVersion($this->connection, $document, (int) $request->document_version_id);
            if ($this->connection->getDriverName() === 'pgsql') {
                $this->connection->statement("SET LOCAL most.legal_signature_mutation = 'service'");
            }
            LegalSignatureRequest::serviceMutation(static function () use ($request): void {
                $request->forceFill(['status' => 'expired', 'completed_at' => now()])->save();
            });
            $this->projection->apply($document);
            $this->audit->recordForActorId('signature_request_expired', $document, null, [
                'source_event_id' => "signature-request-expired:{$request->id}",
                'signature_request_id' => (int) $request->id,
                'document_version_id' => (int) $request->document_version_id,
                'signed_content_hash' => (string) $request->signed_content_hash,
            ]);

            return true;
        }, 3);
    }
}
