<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentSourceReplacementCoordinator
{
    public function __construct(
        private DocumentSourceReplacementTransaction $transaction,
        private EvidenceSourceReplacementInvalidator $invalidator,
    ) {}

    public function commit(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        string $previousSourceVersion,
        string $acceptedSourceVersion,
        callable $accept,
    ): mixed {
        return $this->transaction->transaction(function () use (
            $organizationId,
            $projectId,
            $sessionId,
            $documentId,
            $previousSourceVersion,
            $acceptedSourceVersion,
            $accept,
        ): mixed {
            $result = $accept();
            if ($previousSourceVersion !== '' && $previousSourceVersion !== $acceptedSourceVersion) {
                $this->invalidator->invalidateReplacedDocumentSource(
                    $organizationId,
                    $projectId,
                    $sessionId,
                    $documentId,
                    $previousSourceVersion,
                );
            }

            return $result;
        });
    }
}
