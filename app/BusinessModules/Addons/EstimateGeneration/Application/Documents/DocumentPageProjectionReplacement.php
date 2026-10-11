<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Database\Connection;
use LogicException;

/** Replaces calculated current views, while retaining immutable source assertions. */
final readonly class DocumentPageProjectionReplacement
{
    public function __construct(private Connection $database) {}

    public function replace(int $organizationId, int $projectId, int $sessionId, int $documentId, int $pageNumber, string $sourceVersion): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('document_page_replacement_transaction_required');
        }
        $page = $this->database->table('estimate_generation_document_pages')
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->where('document_id', $documentId)->where('page_number', $pageNumber)->where('source_version', $sourceVersion)
            ->lockForUpdate()->first(['id']);
        if ($page === null) {
            throw new LogicException('document_fact_projection_page_missing');
        }
        $scope = static fn ($query) => $query->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->where('session_id', $sessionId);
        $replaced = $scope($this->database->table('estimate_generation_project_model_fact_projections'))
            ->where('is_current', true)
            ->whereExists(static fn ($query) => $query->selectRaw('1')
                ->from('estimate_generation_project_model_assertions as assertion')
                ->whereColumn('assertion.id', 'estimate_generation_project_model_fact_projections.fact_id')
                ->whereIn('assertion.fact_origin', ['document', 'ai_inference']))
            ->whereExists(static fn ($query) => $query->selectRaw('1')
                ->from('estimate_generation_project_model_fact_evidence as binding')
                ->join('estimate_generation_evidence as evidence', 'evidence.id', '=', 'binding.evidence_id')
                ->whereColumn('binding.fact_id', 'estimate_generation_project_model_fact_projections.fact_id')
                ->where('evidence.organization_id', $organizationId)->where('evidence.project_id', $projectId)->where('evidence.session_id', $sessionId)
                ->where('evidence.source_type', 'document')->where('evidence.source_ref', 'document:'.$documentId)
                ->where('evidence.locator->page', $pageNumber))
            ->update(['is_current' => false, 'invalidated_at' => now(), 'replacement_source_version' => $sourceVersion]);
        if ($replaced > 0) {
            // Derived assertions remain in history; only their current materialized pointers are removed.
            $scope($this->database->table('estimate_generation_project_model_derived_quantity_projections'))->delete();
            foreach (['estimate_generation_project_understanding_runs', 'estimate_generation_technology_planning_runs', 'estimate_generation_completeness_runs'] as $table) {
                $scope($this->database->table($table))->where('is_current', true)->update(['is_current' => false, 'invalidated_at' => now()]);
            }
        }
        // These tables are derived document views, including untagged legacy parser rows.
        foreach (['estimate_generation_document_facts', 'estimate_generation_quantity_takeoffs'] as $table) {
            $scope($this->database->table($table))->where('document_id', $documentId)->where('page_id', (int) $page->id)->delete();
        }
    }
}
