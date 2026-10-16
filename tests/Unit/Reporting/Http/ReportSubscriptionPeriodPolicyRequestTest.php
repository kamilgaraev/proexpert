<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportSubscriptionRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportSubscriptionRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSubscriptionPeriodPolicyRequestTest extends TestCase
{
    #[DataProvider('policies')]
    public function test_create_and_update_validate_supported_period_policy(array $policy, bool $valid): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));

        foreach ([new CreateReportSubscriptionRequest, new UpdateReportSubscriptionRequest] as $request) {
            $rules = array_intersect_key($request->rules(), array_flip(['period_policy', 'period_policy.mode']));
            self::assertSame($valid, $factory->make(['period_policy' => $policy], $rules)->passes());
        }
    }

    public static function policies(): array
    {
        return [
            [['mode' => 'fixed_filters'], true],
            [['mode' => 'previous_day'], true],
            [['mode' => 'previous_week'], true],
            [['mode' => 'previous_month'], true],
            [['mode' => 'whenever'], false],
            [['mode' => 'previous_week', 'ignored_offset' => 2], false],
            [['ignored_offset' => 2], false],
            [[], false],
        ];
    }

    public function test_update_can_omit_policy_but_create_requires_it(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $fields = array_flip(['period_policy', 'period_policy.mode']);

        self::assertTrue($factory->make([], array_intersect_key((new UpdateReportSubscriptionRequest)->rules(), $fields))->passes());
        self::assertFalse($factory->make([], array_intersect_key((new CreateReportSubscriptionRequest)->rules(), $fields))->passes());
    }
}
