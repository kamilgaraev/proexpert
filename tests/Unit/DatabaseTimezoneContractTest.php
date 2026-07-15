<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseTimezoneContractTest extends TestCase
{
    #[Test]
    public function postgres_connections_default_to_the_application_utc_timezone(): void
    {
        $previous = Container::getInstance();
        $root = dirname(__DIR__, 2);
        Container::setInstance(new Application($root));

        try {
            $config = require $root.'/config/database.php';

            self::assertSame('+00:00', $config['connections']['pgsql']['timezone'] ?? null);
        } finally {
            Container::setInstance($previous);
        }
    }
}
