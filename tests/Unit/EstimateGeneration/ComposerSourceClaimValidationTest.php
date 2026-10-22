<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService as Claims;
use PHPUnit\Framework\TestCase;

final class ComposerSourceClaimValidationTest extends TestCase
{
    public function test_closed_candidate_does_not_accept_model_verdicts_numeric_values_or_self_evidence(): void
    {
        $candidate = $this->candidate();
        self::assertTrue(Claims::isCandidate($candidate));
        foreach ([
            [...$candidate, 'passed' => true], [...$candidate, 'unit' => 'шт'],
            [...$candidate, 'value' => ['type' => 'number', 'data' => '3']],
            [...$candidate, 'value' => ['type' => 'string', 'data' => str_repeat('я', 501)]],
            [...$candidate, 'fact_type' => 'equipment_count'],
            [...$candidate, 'document_fact_ids' => ['fact:answer']],
            [...$candidate, 'document_fact_ids' => ['fact:document', 'fact:second']],
        ] as $invalid) {
            self::assertFalse(Claims::isCandidate($invalid));
        }
    }

    public function test_receipt_duplicates_collapse_but_conflicting_interpretations_are_unresolved(): void
    {
        $candidate = $this->candidate();
        self::assertSame([['run_id' => 1, 'candidate' => $candidate]], Claims::foldReceipts([
            ['run_id' => 2, 'candidates' => [$candidate]], ['run_id' => 1, 'candidates' => [$candidate]],
        ]));
        self::assertSame([], Claims::foldReceipts([
            ['run_id' => 1, 'candidates' => [$candidate]],
            ['run_id' => 2, 'candidates' => [[...$candidate, 'entity_id' => 'entity:other']]],
        ]));
        self::assertSame([], Claims::foldReceipts([['run_id' => 1, 'candidates' => [$candidate, $candidate]]]));
    }

    public function test_receipt_projection_and_logical_claim_bounds_fail_closed(): void
    {
        $receipts = array_fill(0, Claims::MAX_PROJECTIONS, ['run_id' => 1, 'candidates' => [$this->candidate()]]);
        self::assertCount(1, Claims::foldReceipts($receipts));
        $receipts[] = $receipts[0];
        self::assertSame([], Claims::foldReceipts($receipts));
        $receipts = [];
        for ($i = 0; $i < 9; $i++) {
            $receipts[] = ['run_id' => $i + 1, 'candidates' => [[...$this->candidate(), 'source_answer_fact_id' => 'fact:answer'.$i]]];
        }
        self::assertSame([], Claims::foldReceipts($receipts));
    }

    public function test_exact_document_correspondence_does_not_turn_a_different_word_or_paraphrase_into_support(): void
    {
        $original = 'Монтаж стендов';
        $witness = ['value_hash' => hash('sha256', json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
        $candidate = [...$this->candidate(), 'value' => ['type' => 'string', 'data' => $original]];
        self::assertTrue(Claims::literalValueCorresponds($candidate, $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds([...$candidate, 'value' => ['type' => 'string', 'data' => 'Монтаж стен']], $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds([...$candidate, 'value' => ['type' => 'string', 'data' => 'Установка стендов']], $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds($candidate, $original, ['value_hash' => str_repeat('0', 64)]));
    }

    private function candidate(): array
    {
        return ['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'entity:equipment',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Насос по спецификации.'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];
    }
}
