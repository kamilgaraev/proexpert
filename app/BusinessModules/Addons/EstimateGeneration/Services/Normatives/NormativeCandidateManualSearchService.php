<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
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

        if ($query !== '') {
            $workItem['normative_search_text'] = $query;
            $workItem['description'] = $query;
        }

        $match = $this->matcher->matchWorkItem($workItem, $context, max(min($limit, 20), 1));

        return [
            'work_item_key' => $workItemKey,
            'query' => $query !== '' ? $query : null,
            'candidates' => array_map(
                fn (array $candidate): array => $this->presenter->present($candidate),
                is_array($match['candidates'] ?? null) ? $match['candidates'] : []
            ),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function findWorkItemContext(EstimateGenerationSession $session, string $workItemKey): array
    {
        $draft = is_array($session->draft_payload ?? null) ? $session->draft_payload : [];
        $regionalContext = $draft['regional_context'] ?? $session->input_payload['regional_context'] ?? [];

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (!is_array($localEstimate)) {
                continue;
            }

            foreach ($localEstimate['sections'] ?? [] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (!is_array($workItem) || (string) ($workItem['key'] ?? '') !== $workItemKey) {
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
