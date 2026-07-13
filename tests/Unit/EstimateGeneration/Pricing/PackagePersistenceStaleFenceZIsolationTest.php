<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class PackagePersistenceStaleFenceZIsolationTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function later_sqlite_consumer_does_not_receive_finalizer_tracking_connection(): void
    {
        self::assertNotInstanceOf(FinalizerTrackingSqliteConnection::class, DB::connection());
    }
}
