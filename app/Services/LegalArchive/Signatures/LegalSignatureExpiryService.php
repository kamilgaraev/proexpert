<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureProviderOperation;
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
        $candidate = (new LegalSignatureRequest)->setConnection($this->connection->getName())
            ->newQuery()->whereKey($requestId)->first();
        if (! $candidate instanceof LegalSignatureRequest) {
            return false;
        }

        return $this->connection->transaction(function () use ($candidate): bool {
            $document = $this->aggregateLock->lockDocument(
                $this->connection,
                (int) $candidate->organization_id,
                (int) $candidate->document_id,
            );
            $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->document_version_id);
            $request = (new LegalSignatureRequest)->setConnection($this->connection->getName())
                ->newQuery()->whereKey($candidate->id)
                ->where('organization_id', $candidate->organization_id)
                ->where('document_id', $candidate->document_id)
                ->where('document_version_id', $candidate->document_version_id)
                ->lockForUpdate()->first();
            if (! $request instanceof LegalSignatureRequest || $request->status !== 'pending'
                || $request->expires_at === null || $request->expires_at->isFuture()) {
                return false;
            }
            if ($this->connection->getDriverName() === 'pgsql') {
                $this->connection->statement("SET LOCAL most.legal_signature_mutation = 'service'");
            }
            LegalSignatureRequest::serviceMutation(static function () use ($request): void {
                $request->forceFill(['status' => 'expired', 'completed_at' => now()])->save();
            });
            $operations = (new LegalSignatureProviderOperation)->setConnection($this->connection->getName())
                ->newQuery()->where('signature_request_id', $request->id)
                ->whereIn('status', ['starting', 'started'])->orderBy('generation')->lockForUpdate()->get();
            foreach ($operations as $operation) {
                LegalSignatureProviderOperation::serviceMutation(static function () use ($operation): void {
                    $operation->forceFill([
                        'status' => 'expired',
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'completed_at' => now(),
                    ])->save();
                });
                $this->audit->recordForActorId('signature_provider_operation_expired', $document, null, [
                    'source_event_id' => "signature-provider-operation:{$operation->id}:expired",
                    'signature_request_id' => (int) $request->id,
                    'operation_id' => (string) $operation->id,
                    'generation' => (int) $operation->generation,
                    'provider_request_fingerprint' => $operation->provider_request_id === null
                        ? null
                        : hash('sha256', (string) $operation->provider_request_id),
                    'session_expires_at' => $operation->session_expires_at?->toAtomString(),
                ]);
            }
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
