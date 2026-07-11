<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Illuminate\Support\Arr;

final readonly class BuildDraftStage implements PipelineStage
{
    public function __construct(private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::BuildDraft;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $data = $context->priorOutputs->payload(ProcessingStage::ResolvePrices);
        $source = $context->priorOutputs->payload(ProcessingStage::UnderstandDocuments);
        $analysis = $data['analysis'];
        $draft = [
            'title' => $source['input']['description'] ?? 'AI draft estimate',
            'generation_mode' => $data['generation_mode'],
            'document_requirements' => $data['document_requirements'],
            'object_profile' => $data['object_profile'],
            'package_plan' => $data['package_plan'],
            'source_documents' => Arr::get($analysis, 'source_documents', []),
            'local_estimates' => $data['local_estimates'],
            'traceability' => [
                'analysis' => Arr::get($analysis, 'detected_structure', []),
                'document_context' => Arr::get($analysis, 'document_context', []),
                'document_source_refs' => Arr::get($analysis, 'document_context.source_refs', []),
            ],
            'regional_context' => Arr::get($analysis, 'regional_context', []),
            'contingency_percent' => Arr::get($analysis, 'object.contingency_percent'),
            'problem_flags' => Arr::get($analysis, 'problem_flags', []),
            'normative_matching' => $this->normativeSummary($data['local_estimates']),
        ];
        $warnings = [];
        $rebuildKey = $source['input']['rebuild_section_key'] ?? null;
        if (is_string($rebuildKey) && $rebuildKey !== '') {
            $found = false;
            foreach ($draft['local_estimates'] as $index => $localEstimate) {
                if (($localEstimate['key'] ?? null) !== $rebuildKey) {
                    continue;
                }
                $draft['local_estimates'][$index]['metadata']['rebuilt_by_user'] = true;
                $found = true;
                break;
            }
            if (! $found) {
                $warnings[] = 'rebuild_section_not_found';
            }
        }

        return $this->results->make($context, $this->stage(), ['draft' => $draft], warnings: $warnings);
    }

    private function normativeSummary(array $localEstimates): array
    {
        $matched = $unmatched = $lowConfidence = 0;
        $sourceType = $versionKey = null;
        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    $match = $workItem['normative_match'] ?? [];
                    if (($match['status'] ?? null) === 'matched') {
                        $matched++;
                        $sourceType = $match['dataset_version']['source_type'] ?? $sourceType;
                        $versionKey = $match['dataset_version']['version_key'] ?? $versionKey;
                        $lowConfidence += (float) ($match['confidence'] ?? 0) < 0.55 ? 1 : 0;
                    } else {
                        $unmatched++;
                    }
                }
            }
        }

        return ['enabled' => true, 'source_type' => $sourceType, 'version_key' => $versionKey, 'matched_work_items' => $matched, 'unmatched_work_items' => $unmatched, 'low_confidence_work_items' => $lowConfidence];
    }
}
