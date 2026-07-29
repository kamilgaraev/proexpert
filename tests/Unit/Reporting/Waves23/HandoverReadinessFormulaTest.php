<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverChecklistFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverEvidenceFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverGateDefinition;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverReadinessFormula;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandoverReadinessFormulaTest extends TestCase
{
    #[Test]
    public function hard_blocker_forbids_ready_even_when_completeness_is_full(): void
    {
        $metric = (new HandoverReadinessFormula)->evaluate(
            new HandoverGateDefinition(
                code: 'final_handover',
                requiredChecklistCodes: ['scope_accepted'],
                requiredDocumentCodes: ['executive_scheme'],
                hardBlockerSourceTypes: ['quality_defect'],
                explicitlyEmptyRequirements: false,
            ),
            [new HandoverChecklistFact('scope_accepted', 'accepted')],
            [
                new HandoverEvidenceFact(
                    eventType: 'document_approved',
                    sourceType: 'document',
                    sourceId: 10,
                    sourceCode: 'executive_scheme',
                    status: 'approved',
                    occurredAt: CarbonImmutable::parse('2026-07-26T09:00:00+03:00'),
                ),
                new HandoverEvidenceFact(
                    eventType: 'finding_opened',
                    sourceType: 'quality_defect',
                    sourceId: 77,
                    sourceCode: null,
                    status: 'open',
                    occurredAt: CarbonImmutable::parse('2026-07-26T10:00:00+03:00'),
                ),
            ],
        );

        self::assertSame('1.00000000', $metric->mandatoryCompleteness);
        self::assertSame('1.00000000', $metric->documentCompleteness);
        self::assertSame(1, $metric->openHardBlockerCount);
        self::assertFalse($metric->ready);
    }

    #[Test]
    public function reinspection_attempt_is_not_a_successful_result(): void
    {
        $metric = (new HandoverReadinessFormula)->evaluate(
            new HandoverGateDefinition(
                code: 'reinspection',
                requiredChecklistCodes: ['inspection_result'],
                requiredDocumentCodes: [],
                hardBlockerSourceTypes: [],
                explicitlyEmptyRequirements: false,
            ),
            [new HandoverChecklistFact('inspection_result', 'ready_for_reinspection')],
            [
                new HandoverEvidenceFact(
                    eventType: 'inspection_attempted',
                    sourceType: 'inspection',
                    sourceId: 25,
                    sourceCode: null,
                    status: 'attempted',
                    occurredAt: CarbonImmutable::parse('2026-07-26T10:00:00+03:00'),
                ),
            ],
        );

        self::assertSame(1, $metric->attemptCount);
        self::assertSame(0, $metric->successfulResultCount);
        self::assertFalse($metric->ready);
    }

    #[Test]
    public function blocker_resolution_and_successful_result_preserve_event_history(): void
    {
        $metric = (new HandoverReadinessFormula)->evaluate(
            new HandoverGateDefinition('handover', [], [], ['constraint'], true),
            [],
            [
                new HandoverEvidenceFact(
                    'finding_opened',
                    'constraint',
                    15,
                    null,
                    'open',
                    CarbonImmutable::parse('2026-07-26T09:00:00+03:00'),
                ),
                new HandoverEvidenceFact(
                    'finding_resolved',
                    'constraint',
                    15,
                    null,
                    'resolved',
                    CarbonImmutable::parse('2026-07-26T10:00:00+03:00'),
                ),
                new HandoverEvidenceFact(
                    'inspection_attempted',
                    'inspection',
                    31,
                    null,
                    'attempted',
                    CarbonImmutable::parse('2026-07-26T11:00:00+03:00'),
                ),
                new HandoverEvidenceFact(
                    'inspection_resulted',
                    'inspection',
                    31,
                    null,
                    'successful',
                    CarbonImmutable::parse('2026-07-26T12:00:00+03:00'),
                ),
            ],
        );

        self::assertSame(0, $metric->openHardBlockerCount);
        self::assertSame(1, $metric->attemptCount);
        self::assertSame(1, $metric->successfulResultCount);
        self::assertTrue($metric->ready);
    }

    #[Test]
    public function replacing_an_approved_document_reverses_readiness_at_same_timestamp(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-26T10:00:00+03:00');
        $metric = (new HandoverReadinessFormula)->evaluate(
            new HandoverGateDefinition('handover', [], ['executive_scheme'], [], false),
            [],
            [
                new HandoverEvidenceFact(
                    'document_approved',
                    'handover_document',
                    10,
                    'executive_scheme',
                    'approved',
                    $occurredAt,
                    1,
                ),
                new HandoverEvidenceFact(
                    'document_replaced',
                    'handover_document',
                    10,
                    'executive_scheme',
                    'draft',
                    $occurredAt,
                    2,
                ),
            ],
        );

        self::assertSame('0.00000000', $metric->documentCompleteness);
        self::assertFalse($metric->ready);
    }

    #[Test]
    public function every_required_owner_source_type_is_a_hard_blocker(): void
    {
        foreach (['rfi', 'change', 'quality_defect', 'constraint'] as $sourceType) {
            $metric = (new HandoverReadinessFormula)->evaluate(
                new HandoverGateDefinition('handover', [], [], [$sourceType], true),
                [],
                [new HandoverEvidenceFact(
                    'blocker_opened',
                    $sourceType,
                    10,
                    null,
                    'open',
                    CarbonImmutable::parse('2026-07-26T10:00:00+03:00'),
                )],
            );

            self::assertSame(1, $metric->openHardBlockerCount, $sourceType);
            self::assertFalse($metric->ready, $sourceType);
        }
    }
}
