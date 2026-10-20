<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\OpeningSystemSpecification;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningWorkItemExpander;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class NormativeCandidateManualSearchService
{
    public function __construct(
        private readonly EstimateNormativeMatcher $matcher,
        private readonly NormativeCandidatePresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(EstimateGenerationSession $session, string $workItemKey, ?string $query, int $limit = 10): array
    {
        [$workItem, $context] = $this->findWorkItemContext($session, $workItemKey);
        $query = trim((string) $query);
        $opening = OpeningWorkItemExpander::isOpeningWork($workItem);
        $system = null;
        if ($opening) {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\CurrentOpeningSystemProofGuard::class)->assertCurrent($session, $workItem);
            $system = $workItem['metadata']['opening_system']['system'];
            $text = OpeningSystemSpecification::searchText($system);
            $workItem['name'] = $text;
            $workItem['description'] = $text.($query === '' ? '' : ' '.$query);
            $workItem['normative_search_text'] = $text.($query === '' ? '' : ' '.$query);
            unset($workItem['work_intent'], $workItem['specialization_scenario'], $workItem['specialization_evidence'], $workItem['normative_rate_code']);
            unset($workItem['metadata']['specialization_scenario'], $workItem['metadata']['material_assumption'], $workItem['metadata']['material_scenario_work_key']);
        } elseif ($query !== '') {
            $workItem['normative_search_text'] = $query;
            $workItem['description'] = $query;
        }

        $match = $this->matcher->searchWorkItemCandidates($workItem, $context, max(min($limit, 20), 1));
        $candidates = is_array($match['candidates'] ?? null) ? $match['candidates'] : [];
        if ($opening) {
            $candidates = array_values(array_filter($candidates, static function (array $candidate) use ($system): bool {
                $composition = $candidate['work_composition'] ?? [];
                $composition = is_array($composition) ? array_filter($composition, 'is_string') : (is_string($composition) ? [$composition] : []);

                return OpeningSystemSpecification::compatible($system, implode(' ', [(string) ($candidate['name'] ?? ''), ...$composition]));
            }));
        }

        return [
            'work_item_key' => $workItemKey,
            'query' => $query !== '' ? $query : null,
            'candidates' => array_map(
                fn (array $candidate): array => $this->presenter->present($candidate, $workItem),
                $candidates
            ),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function findWorkItemContext(EstimateGenerationSession $session, string $workItemKey): array
    {
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\OrdinaryDraftReviewCandidates)
            ->restore(is_array($session->draft_payload ?? null) ? $session->draft_payload : [], $workItemKey);
        $regionalContext = $draft['regional_context'] ?? $session->input_payload['regional_context'] ?? [];

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            foreach ($localEstimate['sections'] ?? [] as $section) {
                if (! is_array($section)) {
                    continue;
                }

                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (! is_array($workItem) || (string) ($workItem['key'] ?? '') !== $workItemKey) {
                        continue;
                    }

                    return [
                        $workItem,
                        [
                            'scope_type' => $localEstimate['scope_type'] ?? null,
                            'local_estimate_title' => $localEstimate['title'] ?? null,
                            'section_title' => $section['title'] ?? null,
                            'source_refs' => $section['source_refs'] ?? $localEstimate['source_refs'] ?? [],
                            'regional_context' => $regionalContext,
                        ],
                    ];
                }
            }
        }

        throw ValidationException::withMessages([
            'work_item_key' => [trans_message('estimate_generation.work_item_not_found')],
        ]);
    }
}
