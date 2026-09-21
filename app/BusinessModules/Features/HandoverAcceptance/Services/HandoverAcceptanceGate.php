<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverChecklistFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverEvidenceFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverGateDefinition;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverReadinessFormula;
use Carbon\CarbonImmutable;

final readonly class HandoverAcceptanceGate
{
    public function __construct(
        private HandoverReadinessFormula $formula,
        private HandoverExecutiveEvidenceService $executiveEvidence,
        private ExecutiveDocumentRequirementsService $requirements,
    ) {}

    public function evaluate(AcceptanceScope $scope, bool $forHandover = false): array
    {
        $scope->load(['checklists.items', 'findings.workRework', 'workReworks', 'handoverPackage.documents.executiveDocumentVersion.document', 'handoverPackage.executiveDocumentSet', 'workQuantities.completedWork.estimateItem', 'workQuantities.completedWork.workType']);
        $requiredChecks = [];
        $checklistFacts = [];
        $documentCodes = [];
        $evidence = [];
        $blockers = [];
        foreach ($scope->workQuantities as $quantity) {
            $work = $quantity->completedWork;
            if ($work === null || (int) $quantity->organization_id !== (int) $scope->organization_id
                || (int) $quantity->project_id !== (int) $scope->project_id
                || (int) $work->organization_id !== (int) $scope->organization_id || (int) $work->project_id !== (int) $scope->project_id
                || (int) $quantity->unit_id !== (int) ($work->estimateItem?->measurement_unit_id ?? $work->workType?->measurement_unit_id)
                || $work->status !== \App\Models\CompletedWork::STATUS_CONFIRMED
                || \Brick\Math\BigDecimal::of($quantity->presented_quantity)->isGreaterThan((string) $work->effectiveCompletedQuantity())) {
                $blockers[] = $this->blocker($scope, 'quantity_source_changed', 'acceptance_scope', $scope->id);
            }
        }
        foreach ($scope->checklists as $checklist) {
            foreach ($checklist->items as $item) {
                if (! $item->is_required) {
                    continue;
                }
                $code = 'checklist_item_'.$item->id;
                $requiredChecks[] = $code;
                $checklistFacts[] = new HandoverChecklistFact($code, $item->status);
                if ($item->status !== 'accepted') {
                    $blockers[] = $this->blocker($scope, 'required_checklist_incomplete', 'checklist_item', $item->id);
                }
            }
        }
        foreach ($scope->findings as $finding) {
            if (! $forHandover && $finding->workRework !== null
                && (int) $finding->workRework->acceptance_scope_id === (int) $scope->id
                && (int) $finding->workRework->organization_id === (int) $scope->organization_id) {
                continue;
            }
            if ($finding->status !== 'resolved') {
                $evidence[] = new HandoverEvidenceFact('finding_opened', 'finding', $finding->id, null, 'open', CarbonImmutable::now());
                $blockers[] = $this->blocker($scope, 'open_findings_block_accept', 'finding', $finding->id);
            }
        }
        $package = $scope->handoverPackage;
        if ($forHandover) {
            foreach ($scope->workReworks as $rework) {
                if ($rework->status !== 'accepted') {
                    $blockers[] = [
                        'code' => 'work_rework_open', 'scope_id' => $scope->id, 'stage' => 'transmission',
                        'message' => trans_message('work_rework.errors.findings_open'),
                        'target' => ['type' => 'work_rework', 'id' => $rework->id],
                    ];
                }
            }
        }
        $set = $package?->executiveDocumentSet;
        $requirementSnapshot = [];
        if ($package?->executive_document_set_id !== null) {
            if ($set === null || (int) $set->organization_id !== (int) $scope->organization_id || (int) $set->project_id !== (int) $scope->project_id) {
                $blockers[] = $this->blocker($scope, 'executive_evidence_invalid', 'acceptance_scope', $scope->id);
            } else {
                $requirements = $this->requirements->readiness($set);
                $blockers = array_merge($blockers, $requirements['blockers']);
                $requirementSnapshot = $set->requirements()->whereNull('superseded_at')->orderBy('id')->get()->toArray();
            }
        }
        $documentIds = $package?->documents->map(fn ($item) => $item->executiveDocumentVersion?->document_id)->filter()->unique()->values()->all() ?? [];
        $latestVersions = $documentIds === [] ? [] : ExecutiveDocumentVersion::query()
            ->where('organization_id', $scope->organization_id)->whereIn('document_id', $documentIds)
            ->selectRaw('document_id, MAX(id) AS latest_id')->groupBy('document_id')->pluck('latest_id', 'document_id')->all();
        $documentSnapshot = [];
        if ($forHandover && $package === null) {
            $blockers[] = $this->blocker($scope, 'required_documents_block_handover', 'acceptance_scope', $scope->id);
        }
        foreach ($package?->documents ?? [] as $document) {
            if (! $document->is_required) {
                continue;
            }
            $code = 'package_document_'.$document->id;
            $documentCodes[] = $code;
            $version = $document->executiveDocumentVersion;
            if ($this->executiveEvidence->isReady($document, $scope, $set?->id, isset($latestVersions[$version?->document_id]) ? (int) $latestVersions[$version->document_id] : null)) {
                $evidence[] = new HandoverEvidenceFact('document_approved', 'document', $document->id, $code, 'approved', CarbonImmutable::now());
                $documentSnapshot[] = [
                    'package_document_id' => $document->id,
                    'document_id' => $version?->document_id,
                    'version_id' => $version?->id,
                    'content_hash' => $version?->content_hash,
                    'external_url' => $document->external_url,
                ];
            } else {
                $blockers[] = $this->blocker($scope, 'required_documents_block_handover', 'package_document', $document->id);
            }
        }
        $metric = $this->formula->evaluate(new HandoverGateDefinition(
            'scope_acceptance', $requiredChecks, $documentCodes, ['finding'], $requiredChecks === [] && $documentCodes === [],
        ), $checklistFacts, $evidence);

        return [
            'ready' => $metric->ready && $blockers === [],
            'mandatory_completeness' => $metric->mandatoryCompleteness,
            'document_completeness' => $metric->documentCompleteness,
            'blockers' => $blockers,
            'evidence_snapshot' => ['executive_document_set_id' => $set?->id, 'requirements' => $requirementSnapshot, 'documents' => $documentSnapshot, 'work_quantities' => $scope->workQuantities->map(fn ($quantity) => $quantity->only(['id', 'completed_work_id', 'unit_id', 'presented_quantity', 'accepted_quantity', 'defect_quantity', 'defect_reason', 'revision']))->values()->all()],
        ];
    }

    private function blocker(AcceptanceScope $scope, string $code, string $type, int $id): array
    {
        return [
            'code' => $code,
            'scope_id' => $scope->id,
            'stage' => 'technical_acceptance',
            'message' => trans_message('handover_acceptance.errors.'.$code),
            'target' => ['type' => $type, 'id' => $id],
        ];
    }
}
