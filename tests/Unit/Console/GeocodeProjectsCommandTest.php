<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\GeocodeProjectsCommand;
use PHPUnit\Framework\TestCase;

class GeocodeProjectsCommandTest extends TestCase
{
    public function test_scheduled_projects_geocode_command_accepts_limit_and_delay_options(): void
    {
        $command = new GeocodeProjectsCommand();

        $this->assertSame('projects:geocode', $command->getName());
        $this->assertContains('geocode:projects', $command->getAliases());
        $this->assertTrue($command->getDefinition()->hasOption('limit'));
        $this->assertTrue($command->getDefinition()->hasOption('delay'));
    }
}
