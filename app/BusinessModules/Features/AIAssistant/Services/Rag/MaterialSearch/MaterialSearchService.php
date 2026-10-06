<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;

final readonly class MaterialSearchService
{
    public function __construct(private ?MaterialSearchCorpus $corpus = null)
    {
    }

    public function search(AuthenticatedPrivateContext $context, MaterialSearchQuery $query): MaterialSearchResult
    {
        $corpus = $this->corpus;

        if (!$corpus instanceof SyntheticMaterialSearchCorpus) {
            return MaterialSearchResult::blocked('search', 'privacy_not_ready');
        }

        $reason = $corpus->guard($context);

        if ($reason !== null) {
            return MaterialSearchResult::blocked('search', $reason);
        }

        $ranked = [];

        foreach ($corpus->records() as $record) {
            if (!$corpus->recordAllowed($context, $record->ref)) {
                continue;
            }

            $score = $record->score($query);

            if ($score > 0) {
                $ranked[] = ['record' => $record, 'score' => $score];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: strcmp($a['record']->title, $b['record']->title));
        $records = array_map(static fn (array $item): MaterialSearchRecord => $item['record'], $ranked);

        return $this->project($context, 'search', array_slice($records, 0, $query->limit),
            array_slice($records, $query->limit));
    }

    public function readSelected(AuthenticatedPrivateContext $context, string $opaqueRef): MaterialSearchResult
    {
        $corpus = $this->corpus;

        if (!$corpus instanceof SyntheticMaterialSearchCorpus) {
            return MaterialSearchResult::blocked('read_selected', 'privacy_not_ready');
        }

        $reason = $corpus->guard($context);

        if ($reason !== null) {
            return MaterialSearchResult::blocked('read_selected', $reason);
        }

        if (preg_match('~^ref_[a-f0-9]{32}$~D', $opaqueRef) !== 1 || !$corpus->recordAllowed($context, $opaqueRef)) {
            return MaterialSearchResult::blocked('read_selected', 'access_denied');
        }

        foreach ($corpus->records() as $record) {
            if ($record->ref === $opaqueRef) {
                return $this->project($context, 'read_selected', [$record], []);
            }
        }

        return MaterialSearchResult::blocked('read_selected', 'access_denied');
    }

    private function project(
        AuthenticatedPrivateContext $context,
        string $kind,
        array $selected,
        array $omitted,
    ): MaterialSearchResult {
        $corpus = $this->corpus;

        if (!$corpus instanceof SyntheticMaterialSearchCorpus) {
            return MaterialSearchResult::blocked($kind, 'privacy_not_ready');
        }

        $reason = $corpus->guard($context);

        if ($reason !== null) {
            return MaterialSearchResult::blocked($kind, $reason);
        }

        $result = MaterialSearchResult::fromRecords($kind, $corpus, $selected, $omitted, $context);
        $reason = $corpus->guard($context);

        if ($reason !== null) {
            return MaterialSearchResult::blocked($kind, $reason);
        }

        foreach (array_merge($selected, $omitted) as $record) {
            if (!$corpus->recordAllowed($context, $record->ref)) {
                return MaterialSearchResult::blocked($kind, 'access_denied');
            }
        }

        if (!$corpus->priceAllowed($context)) {
            return MaterialSearchResult::fromRecords($kind, $corpus, $selected, $omitted, $context);
        }

        return $result;
    }
}
