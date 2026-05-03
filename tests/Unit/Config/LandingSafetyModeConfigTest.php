<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

class LandingSafetyModeConfigTest extends TestCase
{
    public function test_landing_safety_mode_is_disabled_by_default(): void
    {
        $config = require __DIR__ . '/../../../config/app.php';

        $this->assertFalse($config['landing_safety_mode']);
    }
}
