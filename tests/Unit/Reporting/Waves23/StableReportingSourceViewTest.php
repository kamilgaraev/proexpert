<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Support\Reporting\StableReportingSourceView;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StableReportingSourceViewTest extends TestCase
{
    #[Test]
    public function nested_transaction_fails_before_source_capture_uses_unknown_isolation(): void
    {
        $database = new class
        {
            public function transactionLevel(): int
            {
                return 1;
            }
        };
        DB::swap($database);
        $captured = false;

        try {
            (new StableReportingSourceView)->capture(function () use (&$captured): void {
                $captured = true;
            });
            self::fail('Expected nested transaction to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('report_stable_source_nested_transaction', $exception->getMessage());
            self::assertFalse($captured);
        } finally {
            DB::clearResolvedInstance('db');
        }
    }
}
