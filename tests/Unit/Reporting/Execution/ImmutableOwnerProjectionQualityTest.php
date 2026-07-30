<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}

final class QualityRowModel extends Model {}

final class QualitySnapshotModel extends Model {}
