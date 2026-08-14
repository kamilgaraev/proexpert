<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use Illuminate\Database\DatabaseManager;
use JsonException;

final readonly class EloquentEstimateClarificationSource implements EstimateClarificationCatalog, EstimateClarificationSource
{
    public function __construct(
        private DatabaseManager $database,
        private ProjectModelRepository $models,
        private ResolveCurrentEstimateClarification $resolver,
        private int $maxFacts,
    ) {}

    public function findCurrent(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $questionKey,
    ): ?CurrentEstimateClarification {
        foreach ($this->allCurrent($organizationId, $projectId, $sessionId) as $current) {
            if ($current->question->code === $questionKey) {
                return $current;
            }
        }

        return null;
    }

    public function allCurrent(int $organizationId, int $projectId, int $sessionId): array
    {
        $rows = $this->database->table('estimate_generation_document_pages as page')
            ->join('estimate_generation_documents as document', 'document.id', '=', 'page.document_id')
            ->where('document.organization_id', $organizationId)
            ->where('document.project_id', $projectId)
            ->where('document.session_id', $sessionId)
            ->where('document.status', '<>', 'ignored')
            ->whereColumn('page.source_version', 'document.source_version')
            ->where('page.status', '<>', 'excluded')
            ->orderBy('document.id')
            ->orderBy('page.page_number')
            ->limit(10_000)
            ->get([
                'document.id as document_id',
                'page.id as page_id',
                'page.page_number',
                'page.source_version',
                'page.normalized_payload',
            ]);
        $pages = [];
        foreach ($rows as $row) {
            $payload = $this->decode($row->normalized_payload ?? null);
            if ($payload === null) {
                continue;
            }
            $pages[] = [
                ...$payload,
                'document_id' => (int) $row->document_id,
                'page_id' => (int) $row->page_id,
                'page_number' => (int) $row->page_number,
                'source_version' => (string) $row->source_version,
            ];
        }
        $capture = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts,
        );

        return $this->resolver->resolveAll(
            $pages,
            $capture['snapshot'],
            $capture['token'],
        );
    }

    /** @return array<string,mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value) && ! array_is_list($value)) {
            return $value;
        }
        if (! is_string($value) || strlen($value) > 4_194_304) {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
