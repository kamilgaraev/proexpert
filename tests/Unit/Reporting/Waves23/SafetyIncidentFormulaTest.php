<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO\SafetyTransitionFact;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentPolicyVersion;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyIncidentFormula;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafetyIncidentFormulaTest extends TestCase
{
    #[Test]
    public function complete_exposure_produces_exact_frequency(): void
    {
        $fixture = $this->fixture('happy');
        $input = $fixture['input'];

        self::assertSame(
            $fixture['expected']['incident_frequency'],
            (new SafetyIncidentFormula)->frequency($input['qualifying_incident_count'], $input['exposure_hours'], $input['frequency_multiplier']),
        );
    }

    #[Test]
    public function incomplete_exposure_and_unverified_closure_remain_unknown(): void
    {
        $fixture = $this->fixture('boundary');
        $input = $fixture['input'];
        $formula = new SafetyIncidentFormula;
        $fact = new SafetyTransitionFact(
            'corrective_action',
            1,
            'resolved',
            $input['action_status'],
            new DateTimeImmutable($input['verified_at']),
            null,
            new DateTimeImmutable($input['resolved_at']),
            new DateTimeImmutable($input['verified_at']),
            $input['evidence_id'],
        );
        $policy = (new SafetyIncidentPolicyVersion)->forceFill([
            'terminal_statuses' => [
                'incident' => ['closed'],
                'violation' => ['resolved'],
                'corrective_action' => ['verified'],
            ],
            'closure_evidence_required' => true,
        ]);

        self::assertSame($fixture['expected']['incident_frequency'], $formula->frequency($input['qualifying_incident_count'], null, $input['frequency_multiplier']));
        self::assertSame($fixture['expected']['action_closed'], $formula->actionClosure($fact, $policy));
    }

    #[Test]
    public function verified_action_preserves_earlier_resolution_timestamp(): void
    {
        $fact = new SafetyTransitionFact(
            'corrective_action',
            17,
            'resolved',
            'verified',
            new DateTimeImmutable('2026-07-12T12:00:00+03:00'),
            new DateTimeImmutable('2026-07-15T00:00:00+03:00'),
            new DateTimeImmutable('2026-07-10T09:00:00+03:00'),
            new DateTimeImmutable('2026-07-12T12:00:00+03:00'),
            'evidence-17',
        );
        $policy = (new SafetyIncidentPolicyVersion)->forceFill([
            'terminal_statuses' => [
                'incident' => ['closed'],
                'violation' => ['resolved'],
                'corrective_action' => ['verified'],
            ],
            'closure_evidence_required' => true,
        ]);

        self::assertTrue((new SafetyIncidentFormula)->actionClosure($fact, $policy));
    }

    #[Test]
    public function incident_and_violation_closures_require_their_pinned_status_and_evidence(): void
    {
        $policy = (new SafetyIncidentPolicyVersion)->forceFill([
            'terminal_statuses' => [
                'incident' => ['closed'],
                'violation' => ['resolved'],
                'corrective_action' => ['verified'],
            ],
            'closure_evidence_required' => true,
        ]);
        $formula = new SafetyIncidentFormula;
        $closedIncident = new SafetyTransitionFact(
            'incident',
            1,
            'corrective_actions',
            'closed',
            new DateTimeImmutable('2026-07-10T12:00:00+03:00'),
            null,
            null,
            null,
            'incident-evidence',
        );
        $resolvedViolation = new SafetyTransitionFact(
            'violation',
            2,
            'open',
            'resolved',
            new DateTimeImmutable('2026-07-11T12:00:00+03:00'),
            new DateTimeImmutable('2026-07-12T00:00:00+03:00'),
            new DateTimeImmutable('2026-07-11T12:00:00+03:00'),
            null,
            'violation-evidence',
        );
        $missingEvidence = new SafetyTransitionFact(
            'incident',
            3,
            'corrective_actions',
            'closed',
            new DateTimeImmutable('2026-07-10T12:00:00+03:00'),
            null,
            null,
            null,
            null,
        );

        self::assertTrue($formula->isClosure($closedIncident, $policy));
        self::assertTrue($formula->isClosure($resolvedViolation, $policy));
        self::assertFalse($formula->isClosure($missingEvidence, $policy));
    }

    #[Test]
    public function reopen_is_terminal_to_non_terminal_not_terminal_without_evidence(): void
    {
        $policy = (new SafetyIncidentPolicyVersion)->forceFill([
            'terminal_statuses' => [
                'incident' => ['closed'],
                'violation' => ['resolved'],
                'corrective_action' => ['verified'],
            ],
            'closure_evidence_required' => true,
        ]);
        $formula = new SafetyIncidentFormula;

        self::assertTrue($formula->isReopen(new SafetyTransitionFact(
            'incident',
            1,
            'closed',
            'investigation',
            new DateTimeImmutable('2026-07-12T12:00:00+03:00'),
            null,
            null,
            null,
            null,
        ), $policy));
        self::assertFalse($formula->isReopen(new SafetyTransitionFact(
            'incident',
            1,
            'closed',
            'closed',
            new DateTimeImmutable('2026-07-12T12:00:00+03:00'),
            null,
            null,
            null,
            null,
        ), $policy));
    }

    private function fixture(string $case): array
    {
        $json = file_get_contents(__DIR__."/../../../Fixtures/Reporting/waves-2-3/R24/{$case}.json");

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}
