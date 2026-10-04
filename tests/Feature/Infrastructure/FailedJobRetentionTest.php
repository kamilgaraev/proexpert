<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FailedJobRetentionTest extends TestCase
{
    public function test_prune_failed_deletes_only_database_uuid_jobs_older_than_45_days(): void
    {
        self::assertInstanceOf(DatabaseUuidFailedJobProvider::class, app('queue.failer'));

        $now = Carbon::parse('2026-10-04 12:00:00', 'UTC');
        $boundary = $now->copy()->subHours(1080);
        $olderUuid = (string) Str::uuid();
        $boundaryUuid = (string) Str::uuid();
        $freshUuid = (string) Str::uuid();
        $table = (string) config('queue.failed.table');

        Carbon::setTestNow($now);

        try {
            DB::table($table)->insert([
                $this->failedJob($olderUuid, $boundary->copy()->subSecond()),
                $this->failedJob($boundaryUuid, $boundary),
                $this->failedJob($freshUuid, $now->copy()->subDay()),
            ]);

            $this->artisan('queue:prune-failed', ['--hours' => 1080])
                ->assertExitCode(0);

            $remaining = DB::table($table)
                ->whereIn('uuid', [$olderUuid, $boundaryUuid, $freshUuid])
                ->orderBy('uuid')
                ->pluck('uuid')
                ->all();

            self::assertEqualsCanonicalizing([$boundaryUuid, $freshUuid], $remaining);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{uuid: string, connection: string, queue: string, payload: string, exception: string, failed_at: Carbon}
     */
    private function failedJob(string $uuid, Carbon $failedAt): array
    {
        return [
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => $failedAt,
        ];
    }
}
