<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportActorPermissionSlugTest extends TestCase
{
    public function test_terminal_wildcard_permission_is_valid(): void
    {
        $actor = new ReportActor(39, 'active', ['admin.*', 'workforce.view']);

        self::assertSame(['admin.*', 'workforce.view'], $actor->permissionSlugs);
    }

    #[DataProvider('invalidWildcardPermissions')]
    public function test_wildcard_is_rejected_outside_terminal_segment(string $permission): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_actor_permissions_invalid');

        new ReportActor(39, 'active', [$permission]);
    }

    public static function invalidWildcardPermissions(): array
    {
        return [
            'leading wildcard' => ['*.view'],
            'embedded wildcard' => ['admin.*.view'],
            'partial wildcard' => ['admin.v*'],
            'empty segment' => ['admin..*'],
        ];
    }
}
