<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ReadinessPolicyEvaluator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadinessPolicyEvaluatorTest extends TestCase
{
    #[DataProvider('matrix')]
    public function test_v1_is_fail_closed_and_distinguishes_every_report_state(
        array $components,
        ReadinessState $expected,
    ): void {
        $policy = ReadinessPolicyDefinition::v1(17);

        $evaluation = (new ReadinessPolicyEvaluator)->evaluate(
            $policy,
            'standard',
            new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
            $components,
            $policy->hash(),
            scheduleRevisionHash: str_repeat('a', 64),
        );

        self::assertSame($expected, $evaluation->state);
        self::assertSame(3, count($evaluation->componentOutcomes));
    }

    public static function matrix(): array
    {
        $satisfied = static fn (string $category): array => [
            'category' => $category,
            'outcome' => 'satisfied',
            'evidence_type' => 'document',
            'evidence_hash' => hash('sha256', $category),
        ];

        return [
            'ready requires positive evidence for all required categories' => [[
                $satisfied('design'),
                $satisfied('materials'),
                $satisfied('permit'),
            ], ReadinessState::READY],
            'hard unsatisfied prerequisite is blocked' => [[
                $satisfied('design'),
                $satisfied('materials'),
                ['category' => 'permit', 'outcome' => 'unsatisfied'],
            ], ReadinessState::BLOCKED],
            'soft expiry threshold is at risk' => [[
                $satisfied('design'),
                ['category' => 'materials', 'outcome' => 'expiring'],
                $satisfied('permit'),
            ], ReadinessState::AT_RISK],
            'missing required category is unknown' => [[
                $satisfied('design'),
                $satisfied('materials'),
            ], ReadinessState::UNKNOWN],
            'caller cannot declare a category not applicable' => [[
                $satisfied('design'),
                $satisfied('materials'),
                ['category' => 'permit', 'outcome' => 'not_applicable', 'policy_declared' => true],
            ], ReadinessState::UNKNOWN],
        ];
    }

    public function test_valid_waiver_can_satisfy_a_hard_prerequisite_but_expired_or_revoked_waiver_cannot(): void
    {
        $policy = ReadinessPolicyDefinition::v1(17);
        $base = [
            $this->satisfied('design'),
            $this->satisfied('materials'),
        ];
        $validWaiver = [
            'category' => 'permit',
            'outcome' => 'waived',
            'waiver_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'approved_permission' => 'schedule.readiness.waivers.approve',
            'authorization_decision_valid' => true,
            'valid_until' => '2026-08-11T09:00:00+03:00',
            'schedule_revision_hash' => str_repeat('a', 64),
            'revoked' => false,
            'evidence_type' => 'document',
            'evidence_hash' => str_repeat('f', 64),
        ];

        $ready = (new ReadinessPolicyEvaluator)->evaluate(
            $policy,
            'standard',
            new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
            [...$base, $validWaiver],
            $policy->hash(),
            str_repeat('a', 64),
        );
        $expired = (new ReadinessPolicyEvaluator)->evaluate(
            $policy,
            'standard',
            new DateTimeImmutable('2026-08-12T09:00:00+03:00'),
            [...$base, $validWaiver],
            $policy->hash(),
            str_repeat('a', 64),
        );
        $revoked = (new ReadinessPolicyEvaluator)->evaluate(
            $policy,
            'standard',
            new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
            [...$base, [...$validWaiver, 'revoked' => true]],
            $policy->hash(),
            str_repeat('a', 64),
        );

        self::assertSame(ReadinessState::READY, $ready->state);
        self::assertSame(ReadinessState::BLOCKED, $expired->state);
        self::assertSame(ReadinessState::BLOCKED, $revoked->state);
    }

    public function test_waiver_expiry_is_half_open_and_is_invalid_at_exact_valid_until(): void
    {
        $policy = ReadinessPolicyDefinition::v1(17);
        $atExpiry = [
            ...[$this->satisfied('design'), $this->satisfied('materials')],
            [
                'category' => 'permit',
                'outcome' => 'waived',
                'waiver_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
                'authorization_decision_valid' => true,
                'approved_permission' => 'schedule.readiness.waivers.approve',
                'valid_until' => '2026-08-10T09:00:00+03:00',
                'schedule_revision_hash' => str_repeat('a', 64),
                'revoked' => false,
                'evidence_type' => 'document',
                'evidence_hash' => str_repeat('f', 64),
            ],
        ];

        $evaluation = (new ReadinessPolicyEvaluator)->evaluate(
            $policy,
            'standard',
            new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
            $atExpiry,
            $policy->hash(),
            str_repeat('a', 64),
        );

        self::assertSame(ReadinessState::BLOCKED, $evaluation->state);
    }

    public function test_policy_or_schedule_pin_drift_returns_unknown_instead_of_recalculating_from_current_state(): void
    {
        $policy = ReadinessPolicyDefinition::v1(17);
        $components = [
            $this->satisfied('design'),
            $this->satisfied('materials'),
            $this->satisfied('permit'),
        ];
        $evaluator = new ReadinessPolicyEvaluator;

        self::assertSame(
            ReadinessState::UNKNOWN,
            $evaluator->evaluate(
                $policy,
                'standard',
                new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
                $components,
                str_repeat('0', 64),
                str_repeat('a', 64),
            )->state,
        );
        self::assertSame(
            ReadinessState::UNKNOWN,
            $evaluator->evaluate(
                $policy,
                'standard',
                new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
                [...$components, ['schedule_revision_hash' => str_repeat('b', 64)]],
                $policy->hash(),
                str_repeat('a', 64),
            )->state,
        );
    }

    public function test_duplicate_required_category_is_contradictory_and_fails_closed(): void
    {
        $policy = ReadinessPolicyDefinition::v1(17);

        $evaluation = (new ReadinessPolicyEvaluator)->evaluate(
            $policy,
            'standard',
            new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
            [
                $this->satisfied('design'),
                $this->satisfied('materials'),
                $this->satisfied('permit'),
                ['category' => 'permit', 'outcome' => 'unsatisfied'],
            ],
            $policy->hash(),
            str_repeat('a', 64),
        );

        self::assertSame(ReadinessState::UNKNOWN, $evaluation->state);
        self::assertContains('component_contradictory_or_duplicate', $evaluation->reasonCodes);
    }

    #[DataProvider('malformedPolicyProvider')]
    public function test_policy_schema_is_closed_and_rejects_malformed_calendar_rules(array $mutations): void
    {
        $canonical = ReadinessPolicyDefinition::v1(17)->canonicalDefinition();
        unset($canonical['organization_id'], $canonical['policy_code'], $canonical['revision'], $canonical['semantic_version']);
        foreach ($mutations as [$path, $value]) {
            $target = &$canonical;
            foreach ($path as $segment) {
                $target = &$target[$segment];
            }
            $target = $value;
            unset($target);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_policy_schema_invalid');

        new ReadinessPolicyDefinition(17, 'lookahead-readiness.custom', '1.0.0', 1, $canonical);
    }

    public static function malformedPolicyProvider(): array
    {
        return [
            'missing calendar cutoff' => [[[['business_calendar', 'cutoff_local_time'], null]]],
            'unsupported DST ambiguity' => [[[['business_calendar', 'dst_ambiguity'], 'guess']]],
            'open task rule' => [[[['task_classes', 'standard', 'required', 'permit', 'unexpected'], true]]],
            'unknown top-level policy key' => [[[['caller_override'], true]]],
        ];
    }

    private function satisfied(string $category): array
    {
        return [
            'category' => $category,
            'outcome' => 'satisfied',
            'evidence_type' => 'document',
            'evidence_hash' => hash('sha256', $category),
        ];
    }
}
