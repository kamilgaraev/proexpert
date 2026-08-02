<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadReadinessMetric;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessSnapshotMaterializer;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ImmutableOwnerProjectionQualityTest extends TestCase
{
    #[Test]
    public function persisted_unknown_metrics_keep_quality_partial_on_every_page(): void
    {
        require_once dirname(__DIR__, 4).'/app/Support/Reporting/ImmutableOwnerProjectionReader.php';

        $reader = new ImmutableOwnerProjectionReader(
            QualityRowModel::class,
            QualitySnapshotModel::class,
            ['recognized_on' => 'recognized_on'],
        );
        $quality = new ReflectionMethod($reader, 'quality');
        $snapshotTotals = [
            'groups' => [],
            'unknown_metrics' => ['accepted_amount_minor'],
        ];

        $firstPageQuality = $quality->invoke($reader, $snapshotTotals);
        $secondPageQuality = $quality->invoke($reader, $snapshotTotals);

        self::assertSame(ReportQualityStatus::PARTIAL, $firstPageQuality->status);
        self::assertSame(['accepted_amount_minor'], $firstPageQuality->unknownMetrics);
        self::assertEquals($firstPageQuality, $secondPageQuality);
    }

    #[Test]
    public function lookahead_materializer_persists_warning_quality_for_every_page(): void
    {
        require_once dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/DTO/'
            .'LookaheadReadinessMetric.php';
        require_once dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
            .'LookaheadReadinessSnapshotMaterializer.php';

        $metric = new LookaheadReadinessMetric(
            1,
            true,
            false,
            [9],
            1,
            0,
            'LOOKAHEAD_WAIVER_EXPIRED',
            0,
        );
        $materializer = (new ReflectionClass(LookaheadReadinessSnapshotMaterializer::class))
            ->newInstanceWithoutConstructor();
        $snapshotUnknownMetrics = new ReflectionMethod($materializer, 'snapshotUnknownMetrics');
        $totals = [
            'eligible_tasks' => 1,
            'unknown_metrics' => $snapshotUnknownMetrics->invoke($materializer, [[null, $metric]]),
        ];

        $reader = new ImmutableOwnerProjectionReader(
            QualityRowModel::class,
            QualitySnapshotModel::class,
            ['recognized_on' => 'recognized_on'],
        );
        $quality = new ReflectionMethod($reader, 'quality');
        $firstPageQuality = $quality->invoke($reader, $totals);
        $secondPageQuality = $quality->invoke($reader, $totals);

        self::assertSame(['waiver_validity'], $totals['unknown_metrics']);
        self::assertSame(ReportQualityStatus::PARTIAL, $firstPageQuality->status);
        self::assertEquals($firstPageQuality, $secondPageQuality);
    }
}

final class QualityRowModel extends Model {}

final class QualitySnapshotModel extends Model {}
