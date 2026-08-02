<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Reporting\Support\OrganizationBusinessTimezone;
use App\Models\Organization;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class OrganizationBusinessTimezoneTest extends TestCase
{
    public function test_owner_setting_is_used_instead_of_process_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $organization = new Organization([
                'multi_org_settings' => ['default_timezone' => 'Asia/Yekaterinburg'],
            ]);

            self::assertSame(
                'Asia/Yekaterinburg',
                (new OrganizationBusinessTimezone)->resolve($organization)->getName(),
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_product_default_is_an_explicit_timezone(): void
    {
        $timezone = (new OrganizationBusinessTimezone)->resolve(new Organization);

        self::assertInstanceOf(DateTimeZone::class, $timezone);
        self::assertSame('Europe/Moscow', $timezone->getName());
    }
}
