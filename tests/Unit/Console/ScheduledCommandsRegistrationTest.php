<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\DatabaseLessTestCase;

final class ScheduledCommandsRegistrationTest extends DatabaseLessTestCase
{
    public function test_scheduled_artisan_commands_are_registered(): void
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        $commands = array_keys($kernel->all());

        foreach ($this->scheduledCommands() as $command) {
            $this->assertContains($command, $commands);
        }
    }

    public function test_failed_jobs_are_pruned_daily_after_45_days_on_one_scheduler(): void
    {
        $schedule = $this->scheduleSource();
        $command = "Schedule::command('queue:prune-failed --hours=1080')";

        $this->assertTrue(
            str_contains($schedule, $command),
            'Failed jobs retention schedule is missing.',
        );
        $position = strpos($schedule, $command);

        $this->assertIsInt($position);
        $block = substr($schedule, $position, 300);
        $this->assertStringContainsString('->daily()', $block);
        $this->assertStringContainsString('->withoutOverlapping(', $block);
        $this->assertStringContainsString('->onOneServer()', $block);
    }

    public function test_all_regions_resource_prices_are_scheduled_after_all_regions_worker_prices(): void
    {
        $schedule = $this->scheduleSource();
        $regional = "Schedule::command('estimates:regional-prices:sync-fgiscs --all-regions --latest-only')";
        $building = "Schedule::command('estimates:regional-prices:sync-fgiscs-building-resources --all-regions')";

        $this->assertStringContainsString($building, $schedule);
        $this->assertGreaterThan(strpos($schedule, $regional), strpos($schedule, $building));
        $buildingPosition = strpos($schedule, $building);

        $this->assertIsInt($buildingPosition);
        $block = substr($schedule, $buildingPosition, 800);
        $this->assertStringContainsString("->dailyAt('13:00')", $block);
        $this->assertStringContainsString('->withoutOverlapping(720)', $block);
        $this->assertStringContainsString(
            "->createMutexNameUsing('estimate-generation:fgiscs-all-regions:v1')",
            $block
        );
        $regionalPosition = strpos($schedule, $regional);
        $this->assertIsInt($regionalPosition);
        $regionalBlock = substr($schedule, $regionalPosition, 700);
        $this->assertStringContainsString(
            "->createMutexNameUsing('estimate-generation:fgiscs-all-regions:v1')",
            $regionalBlock
        );
        $this->assertStringContainsString('->runInBackground()', $block);
        $this->assertStringContainsString('->onFailure(', $block);
        $this->assertStringContainsString('schedule-building-resource-prices-sync.log', $block);
    }

    public function test_building_resource_sync_command_logs_exception_identity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/Normatives/Console/Commands/SyncFgiscsBuildingResourcePricesCommand.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("Log::error('[EstimateGeneration] FGIS CS building resource price sync failed.'", $source);
        $this->assertStringContainsString("'exception_class' => \$exception::class", $source);
        $this->assertStringContainsString('$exception->safeContext()', $source);
        $this->assertStringNotContainsString("'exception_message'", $source);
        $this->assertStringContainsString('{--all-regions}', $source);
        $this->assertStringContainsString('$service->syncAllRegions(', $source);
    }

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $schedule = $this->scheduleSource();
        preg_match_all("/Schedule::command\\('([^']+)'\\)/", $schedule, $matches);

        return array_values(array_unique(array_map(
            static fn (string $command): string => strtok($command, ' ') ?: $command,
            $matches[1],
        )));
    }

    private function scheduleSource(): string
    {
        $schedule = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');

        $this->assertIsString($schedule);

        return $schedule;
    }
}
