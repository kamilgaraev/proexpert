<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Laravel\Horizon\Console\WorkCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

final class HorizonWorkerCompatibilityTest extends TestCase
{
    public function test_horizon_can_build_worker_options_for_the_installed_framework(): void
    {
        $command = new class($this->createMock(Worker::class), $this->createMock(Repository::class)) extends WorkCommand
        {
            public function workerOptions(): WorkerOptions
            {
                $this->input = new ArrayInput([], $this->getDefinition());

                return $this->gatherWorkerOptions();
            }
        };

        self::assertSame(0, (int) $command->workerOptions()->stopWhenEmptyFor);
    }
}
