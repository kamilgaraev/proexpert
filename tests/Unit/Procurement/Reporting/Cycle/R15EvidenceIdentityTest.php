<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15EvidenceIdentity;
use PHPUnit\Framework\TestCase;

final class R15EvidenceIdentityTest extends TestCase
{
    public function test_builder_declares_full_core_reporting_tree_and_locked_php_dependencies(): void
    {
        $builder = file_get_contents(dirname(__DIR__, 5).'/scripts/reporting/build-r15-publication-candidate.php');
        self::assertIsString($builder);
        self::assertStringContainsString("gitTreePaths(\$root, \$sha, 'app/BusinessModules/Core/Reporting')", $builder);
        self::assertStringContainsString("['composer.json', 'composer.lock']", $builder);
    }

    public function test_identity_changes_when_declared_composer_or_core_reporting_dependency_changes(): void
    {
        $artifacts = [
            'core_reporting_delivery_and_drill' => [
                ['path' => 'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportRenderer.php', 'sha256' => str_repeat('a', 64)],
                ['path' => 'composer.json', 'sha256' => str_repeat('b', 64)],
                ['path' => 'composer.lock', 'sha256' => str_repeat('c', 64)],
            ],
        ];
        $identity = R15EvidenceIdentity::fromArtifacts($artifacts);
        $composerChanged = $artifacts;
        $composerChanged['core_reporting_delivery_and_drill'][1]['sha256'] = str_repeat('d', 64);
        $coreChanged = $artifacts;
        $coreChanged['core_reporting_delivery_and_drill'][0]['sha256'] = str_repeat('e', 64);

        self::assertNotSame($identity, R15EvidenceIdentity::fromArtifacts($composerChanged));
        self::assertNotSame($identity, R15EvidenceIdentity::fromArtifacts($coreChanged));
    }
}
