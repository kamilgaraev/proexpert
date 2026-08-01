<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Quality;

use App\BusinessModules\Core\Reporting\Infrastructure\Quality\FixedRootJointQG14EvidenceSource;
use PHPUnit\Framework\TestCase;

final class FixedRootJointQG14EvidenceSourceTest extends TestCase
{
    public function test_executes_the_single_fixed_two_root_command_and_returns_typed_evidence(): void
    {
        $capturedArgv = [];
        $source = new FixedRootJointQG14EvidenceSource('C:/admin', 'C:/backend', static function (array $argv) use (&$capturedArgv): array {
            $capturedArgv = $argv;

            return [0, '{"admin_forbidden_symbol_matches":0,"backend_forbidden_symbol_matches":0,"combined_forbidden_symbol_matches":0,"qg14_admin_sha256":"' . str_repeat('1', 64) . '","qg14_backend_sha256":"' . str_repeat('2', 64) . '","qg14_combined_sha256":"' . str_repeat('3', 64) . '"}', ''];
        });

        $evidence = $source->execute();

        self::assertSame('qg14_forbidden_symbols', $evidence->commandId);
        self::assertSame(['node', 'scripts/verify-reporting-cutover.mjs', '--admin-root=C:/admin', '--backend-root=C:/backend'], $capturedArgv);
    }

    public function test_rejects_non_empty_stderr_before_returning_evidence(): void
    {
        $source = new FixedRootJointQG14EvidenceSource('C:/admin', 'C:/backend', static fn (): array => [0, '{}', 'unexpected']);

        $this->expectException(\RuntimeException::class);
        $source->execute();
    }
}
