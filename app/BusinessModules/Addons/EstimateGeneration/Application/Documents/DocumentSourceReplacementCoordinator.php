<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;

final readonly class DocumentSourceReplacementCoordinator
{
    public function __construct(
        private DocumentSourceReplacementTransaction $transaction,
        private EvidenceSourceReplacementInvalidator $invalidator,
        private DocumentSourceReplacementPageStore $pages,
        private ProjectModelRepository $projectModel,
    ) {}

    public function commit(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        ?string $previousSourceVersion,
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
            if ($previousSourceVersion !== $acceptedSourceVersion) {
                $this->pages->removeStalePages(
                    $organizationId,
                    $projectId,
                    $sessionId,
                    $documentId,
                    $acceptedSourceVersion,
                );
                if ($previousSourceVersion !== null && $previousSourceVersion !== '') {
                    $this->invalidator->invalidateReplacedDocumentSource(
                        $organizationId,
                        $projectId,
                        $sessionId,
                        $documentId,
                        $previousSourceVersion,
                    );
                    $this->projectModel->invalidateSourceVersion(
                        $organizationId,
                        $projectId,
                        $sessionId,
                        $previousSourceVersion,
                        $acceptedSourceVersion,
                    );
                }
            }

            return $accept();
        });
    }
}
