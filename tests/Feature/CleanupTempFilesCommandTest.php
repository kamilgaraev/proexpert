<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CleanupTempFilesCommandTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/temp');
        File::ensureDirectoryExists($this->tempPath);
    }

    protected function tearDown(): void
    {
        File::delete([
            "{$this->tempPath}/old-test-export.pdf",
            "{$this->tempPath}/fresh-test-export.pdf",
            "{$this->tempPath}/dry-run-test-export.pdf",
        ]);

        parent::tearDown();
    }

    public function test_deletes_temp_files_older_than_configured_hours(): void
    {
        $oldFile = "{$this->tempPath}/old-test-export.pdf";
        $freshFile = "{$this->tempPath}/fresh-test-export.pdf";

        File::put($oldFile, 'old');
        File::put($freshFile, 'fresh');
        touch($oldFile, now()->subHours(49)->timestamp);
        touch($freshFile, now()->subHour()->timestamp);

        $this->artisan('temp-files:cleanup', ['--hours' => 48])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($freshFile);
    }

    public function test_dry_run_keeps_matching_temp_files(): void
    {
        $file = "{$this->tempPath}/dry-run-test-export.pdf";

        File::put($file, 'old');
        touch($file, now()->subHours(49)->timestamp);

        $this->artisan('temp-files:cleanup', ['--hours' => 48, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFileExists($file);
    }
}
