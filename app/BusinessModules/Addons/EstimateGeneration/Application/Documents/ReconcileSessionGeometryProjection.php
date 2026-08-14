<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\DeterministicGeometryCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryProjectionSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final readonly class ReconcileSessionGeometryProjection
{
    private const MAX_PAGES = 10_000;

    public function __construct(
        private Connection $database,
        private ProjectModelRepository $models,
        private DeterministicGeometryCalculator $calculator,
        private DocumentSemanticUnderstandingSummarizer $summarizer,
    ) {}

    public function reconcile(EstimateGenerationSession $session): bool
    {
        return $this->database->transaction(function () use ($session): bool {
            $documents = $this->documentQuery()
                ->where('organization_id', $session->organization_id)
                ->where('project_id', $session->project_id)
                ->where('session_id', $session->getKey())
                ->where('status', '<>', 'ignored')
                ->with(['pages' => static fn ($query) => $query->orderBy('page_number')->orderBy('id')])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($documents->isEmpty()) {
                return false;
            }
            foreach ($documents as $document) {
                if ((string) $document->source_version === ''
                    || (string) $document->units_finalized_source_version !== (string) $document->source_version
                    || (string) $document->units_reconciled_source_version !== (string) $document->source_version) {
                    return false;
                }
            }

            $documentIdentities = $documents->map(static fn (EstimateGenerationDocument $document): array => [
                'id' => (int) $document->getKey(),
                'source_version' => (string) $document->source_version,
            ])->all();
            $sourceVersion = GeometryProjectionSourceVersion::fromDocuments($documentIdentities);
            $pages = [];
            foreach ($documents as $document) {
                foreach ($document->pages as $page) {
                    if ((string) $page->status === 'excluded'
                        || (string) $page->source_version !== (string) $document->source_version) {
                        continue;
                    }
                    if (count($pages) >= self::MAX_PAGES) {
                        throw new RuntimeException('geometry_reconciliation_pages_unbounded');
                    }
                    $payload = is_array($page->normalized_payload) ? $page->normalized_payload : [];
                    $geometry = is_array($payload['geometry_expert'] ?? null)
                        ? GeometryExpertResult::fromArray($payload['geometry_expert'])
                        : new GeometryExpertResult([], [], [], []);
                    $pages[] = [
                        'model' => $page,
                        'document' => $document,
                        'payload' => $payload,
                        'result' => $geometry,
                        'document_id' => (int) $document->getKey(),
                        'page_id' => (int) $page->getKey(),
                        'page_number' => (int) $page->page_number,
                        'source_version' => (string) $document->source_version,
                    ];
                }
            }
            if ($pages === []) {
                return false;
            }
            $result = $this->calculator->reconcileResults(array_map(
                static fn (array $page): array => [
                    'result' => $page['result'],
                    'document_id' => $page['document_id'],
                    'page_id' => $page['page_id'],
                    'page_number' => $page['page_number'],
                    'source_version' => $page['source_version'],
                ],
                $pages,
            ));
            $quantities = $this->calculator->domainQuantities(new GeometryExpertInput(
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->getKey(),
                $sourceVersion,
                [],
            ), $result);
            $this->models->replaceDerivedQuantityFormulaProjectionSet(
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->getKey(),
                DeterministicGeometryCalculator::FORMULA_VERSION,
                $quantities,
            );

            $crossQuestions = array_values(array_filter(
                $result->questions,
                static fn (array $question): bool => str_starts_with((string) ($question['code'] ?? ''), 'cross_sheet_geometry_'),
            ));
            foreach ($pages as $index => &$page) {
                $payload = $page['payload'];
                $arbitration = is_array($payload['document_arbitration']['questions'] ?? null)
                    ? $payload['document_arbitration']['questions'] : [];
                $geometry = is_array($payload['geometry_expert']['questions'] ?? null)
                    ? $payload['geometry_expert']['questions'] : [];
                $payload['ai_questions'] = array_values([
                    ...$arbitration,
                    ...$geometry,
                    ...($index === 0 ? $crossQuestions : []),
                ]);
                $payload['geometry_reconciliation'] = [
                    'source_version' => $sourceVersion,
                    'formula_version' => DeterministicGeometryCalculator::FORMULA_VERSION,
                    'question_count' => count($result->questions),
                    'conflict_count' => count($result->conflicts),
                ];
                $page['payload'] = $payload;
                $page['model']->forceFill(['normalized_payload' => $payload])->save();
            }
            unset($page);

            foreach ($documents as $document) {
                $documentPages = array_values(array_filter(
                    $pages,
                    static fn (array $page): bool => $page['document_id'] === (int) $document->getKey(),
                ));
                $payloads = array_column($documentPages, 'payload');
                $semantic = $this->summarizer->summarize($payloads);
                $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
                unset($factsSummary['semantic_understanding']);
                $structured = is_array($document->structured_payload) ? $document->structured_payload : [];
                $structured['pages'] = array_map(static fn (array $page): array => [
                    'page_number' => $page['page_number'],
                    'text' => $page['model']->text,
                    'confidence' => $page['model']->confidence,
                    'normalized_payload' => $page['payload'],
                ], $documentPages);
                $document->forceFill([
                    'facts_summary' => [...$factsSummary, ...$semantic],
                    'structured_payload' => $structured,
                ])->save();
            }

            return true;
        }, 3);
    }

    /** @return Builder<EstimateGenerationDocument> */
    private function documentQuery(): Builder
    {
        $model = new EstimateGenerationDocument;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }
}
