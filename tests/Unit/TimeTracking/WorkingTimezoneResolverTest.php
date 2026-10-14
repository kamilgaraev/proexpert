<?php

declare(strict_types=1);

namespace Tests\Unit\TimeTracking;

use App\BusinessModules\Features\TimeTracking\Support\WorkingTimezoneResolver;
use PHPUnit\Framework\TestCase;

final class WorkingTimezoneResolverTest extends TestCase
{
    public function test_unique_confirmed_contract_timezone_is_used(): void
    {
        self::assertSame(['timezone' => 'Europe/Moscow', 'employment_contract_id' => 1], WorkingTimezoneResolver::confirmedTimezone([['id' => 1, 'timezone' => 'Europe/Moscow']]));
    }

    public function test_same_timezone_in_multiple_contracts_does_not_guess_contract_allocation(): void
    {
        self::assertSame(['timezone' => 'Europe/Moscow', 'employment_contract_id' => null], WorkingTimezoneResolver::confirmedTimezone([['id' => 1, 'timezone' => 'Europe/Moscow'], ['id' => 2, 'timezone' => 'Europe/Moscow']]));
    }

    public function test_ambiguous_unlinked_or_unknown_profiles_require_reconciliation(): void
    {
        foreach ([[], [['id' => 1, 'timezone' => null]], [['id' => 1, 'timezone' => 'MSK']], [['id' => 1, 'timezone' => 'Europe/Moscow'], ['id' => 2, 'timezone' => 'Asia/Vladivostok']]] as $contracts) {
            self::assertSame(['timezone' => null, 'employment_contract_id' => null], WorkingTimezoneResolver::confirmedTimezone($contracts));
        }
    }

    public function test_explicit_contract_selects_its_confirmed_timezone(): void
    {
        self::assertSame(['timezone' => 'Asia/Vladivostok', 'employment_contract_id' => 2], WorkingTimezoneResolver::confirmedTimezone([['id' => 1, 'timezone' => 'Europe/Moscow'], ['id' => 2, 'timezone' => 'Asia/Vladivostok']], 2));
    }
}
