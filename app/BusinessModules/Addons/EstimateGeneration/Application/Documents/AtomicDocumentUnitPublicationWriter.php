<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use Illuminate\Database\Connection;
use LogicException;

final readonly class AtomicDocumentUnitPublicationWriter implements DocumentUnitPublicationWriter
{
    public function __construct(
        private Connection $database,
        private ProjectModelEvidenceWriter $evidence,
    ) {}

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $this->evidence->transaction($organizationId, $sessionId, $callback);
    }

    public function write(
        DocumentUnitPublication $publication,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        int $pageNumber,
        string $sourceVersion,
    ): void {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('document_unit_publication_transaction_required');
        }
        $publication->assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        if ($publication->claims === []) {
            return;
        }
        $this->evidence->writeArbitration(
            $publication->claims,
            $publication->decisions,
            $documentId,
            $pageNumber,
        );
    }
}
