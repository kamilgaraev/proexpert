<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLifecycleCompleteness;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedProductionOwnerCompletenessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4).'/vendor/autoload.php';
        require_once dirname(__DIR__, 4)
            .'/app/Services/CompletedWork/Reporting/AcceptedProduction/Models/'
            .'ProductionAcceptanceEvent.php';
        require_once dirname(__DIR__, 4)
            .'/app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionLifecycleCompleteness.php';
    }

    #[Test]
    public function filtered_owner_candidate_without_event_remains_a_readiness_gap(): void
    {
        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $this->scope(),
            new DateTimeImmutable('2026-07-30T12:00:00Z'),
            collect(),
            ['candidates' => [$this->candidate(11, 101, 'accepted')]],
        );

        self::assertSame([[
            'owner_version_id' => 501,
            'performance_act_id' => 11,
            'reason' => 'accepted_production_owner_history_unproven',
            'source_line_id' => 101,
            'source_line_type' => 'performance_act_line',
        ]], $gaps);
    }

    #[Test]
    public function immutable_owner_candidate_is_not_affected_by_later_membership_shape(): void
    {
        $event = new ProductionAcceptanceEvent;
        $event->forceFill([
            'event_type' => 'accepted',
            'performance_act_id' => 11,
            'source_line_id' => 101,
            'source_line_type' => 'performance_act_line',
        ]);
        $universe = ['candidates' => [$this->candidate(11, 101, 'accepted')]];

        self::assertSame([], (new AcceptedProductionLifecycleCompleteness)->inspect(
            $this->scope(),
            new DateTimeImmutable('2026-07-30T12:00:00Z'),
            collect([$event]),
            $universe,
        ));
        self::assertSame(101, $universe['candidates'][0]['source_line_id']);
    }

    #[Test]
    public function approved_legacy_act_without_provable_membership_is_never_ready_at_zero_over_zero(): void
    {
        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $this->scope(),
            new DateTimeImmutable('2026-07-30T12:00:00Z'),
            collect(),
            [
                'candidates' => [],
                'legacy_gaps' => [[
                    'performance_act_id' => 11,
                    'project_id' => 7,
                    'reason' => 'historical_membership_unprovable',
                ]],
            ],
        );

        self::assertSame('historical_membership_unprovable', $gaps[0]['reason']);
        self::assertSame(11, $gaps[0]['performance_act_id']);
    }

    private function candidate(int $actId, int $lineId, string $eventType): array
    {
        return [
            'event_type' => $eventType,
            'owner_source_hash' => str_repeat('a', 64),
            'owner_version_id' => 501,
            'performance_act_id' => $actId,
            'project_id' => 7,
            'source_line_id' => $lineId,
            'source_line_type' => 'performance_act_line',
            'work_id' => 301,
        ];
    }

    private function scope(): ReportScope
    {
        return new ReportScope(
            1,
            [1],
            [7],
            [],
            new DateTimeZone('UTC'),
        );
    }
}
