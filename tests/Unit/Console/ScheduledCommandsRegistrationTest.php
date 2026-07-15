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

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $schedule = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');

        $this->assertIsString($schedule);
        preg_match_all("/Schedule::command\\('([^']+)'\\)/", $schedule, $matches);

        return array_values(array_unique(array_map(
            static fn (string $command): string => strtok($command, ' ') ?: $command,
            $matches[1],
        )));
    }
}
