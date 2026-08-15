<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentUnitOutputSemanticStateTest extends TestCase
{
    #[Test]
    public function legacy_questions_do_not_change_document_semantic_state_but_quarantine_does(): void
    {
        $questions = new DocumentUnitOutput('questions-v1', '', normalizedPayload: [
            'document_arbitration' => ['result_state' => 'questions', 'decisions' => []],
            'ai_questions' => [['code' => 'server-question']],
        ]);
        $partial = new DocumentUnitOutput('partial-v1', '', normalizedPayload: [
            'document_arbitration' => [
                'result_state' => 'partial',
                'decisions' => [['status' => 'candidate']],
                'quarantined_intents' => [['reason' => 'invalid']],
            ],
        ]);

        self::assertSame('ready', $questions->semanticState());
        self::assertSame('partial', $partial->semanticState());
    }
}
