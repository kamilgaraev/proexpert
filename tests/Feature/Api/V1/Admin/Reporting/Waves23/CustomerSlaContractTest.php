<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class CustomerSlaContractTest extends TestCase
{
    public function test_unknown_actor_side_is_fail_closed_and_drilldown_excludes_comment_bodies(): void
    {
        $root = dirname(__DIR__, 7);
        $formula = file_get_contents($root.'/app/Services/Customer/Reporting/Sla/Services/CustomerSlaFormula.php');
        $materializer = file_get_contents($root.'/app/Services/Customer/Reporting/Sla/Services/CustomerSlaSnapshotMaterializer.php');

        self::assertIsString($formula);
        self::assertIsString($materializer);
        self::assertStringContainsString('if (! $actorSideComplete)', $formula);
        self::assertStringNotContainsString('comment_body', $materializer);
        self::assertStringNotContainsString('comment_text', $materializer);
    }
}
