<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessRedactionProjector;
use PHPUnit\Framework\TestCase;

final class LookaheadReadinessRedactionProjectorTest extends TestCase
{
    public function test_redaction_preserves_state_and_counts_but_hides_sensitive_blocker_fields(): void
    {
        $row = [
            'state' => 'blocked',
            'blocker_count' => 2,
            'blocker_category' => 'permit',
            'blocker_title' => 'Permit not approved',
            'blocker_description' => 'Commercial counterparty details',
            'owner_ref' => 'user:77',
            'vendor_ref' => 'vendor:91',
            'evidence_locator' => 'org-10/readiness/permit-900',
        ];

        $projected = (new LookaheadReadinessRedactionProjector)->project($row, []);

        self::assertSame('blocked', $projected['state']);
        self::assertSame(2, $projected['blocker_count']);
        self::assertSame('permit', $projected['blocker_category']);
        self::assertNull($projected['blocker_title']);
        self::assertNull($projected['blocker_description']);
        self::assertNull($projected['owner_ref']);
        self::assertNull($projected['vendor_ref']);
        self::assertNull($projected['evidence_locator']);
        self::assertSame([
            'blocker_description',
            'blocker_title',
            'evidence_locator',
            'owner_ref',
            'vendor_ref',
        ], $projected['redacted_fields']);
    }

    public function test_ui_and_export_use_the_same_projection_for_the_same_permissions(): void
    {
        $row = [
            'state' => 'at_risk',
            'blocker_count' => 1,
            'blocker_title' => 'Material certificate expires',
            'blocker_description' => 'Certificate serial',
            'owner_ref' => 'role:supply_manager',
            'vendor_ref' => 'vendor:91',
            'evidence_locator' => 'org-10/readiness/certificate-900',
        ];
        $permissions = [
            'schedule.readiness.blockers.details.view',
            'schedule.readiness.blockers.identity.view',
        ];
        $projector = new LookaheadReadinessRedactionProjector;

        self::assertSame(
            $projector->project($row, $permissions),
            $projector->projectForExport($row, $permissions),
        );
        self::assertSame('Material certificate expires', $projector->project($row, $permissions)['blocker_title']);
        self::assertNull($projector->project($row, $permissions)['evidence_locator']);
    }
}
