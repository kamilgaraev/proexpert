<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\AcceptedProductionReportOptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use PHPUnit\Framework\TestCase;

final class AcceptedProductionOptionsRequestContractTest extends TestCase
{
    public function test_client_cannot_replace_server_owned_context(): void
    {
        $rules = (new AcceptedProductionReportOptionsRequest)->rules();

        foreach ([
            'organization_id',
            'current_organization_id',
            'holding_organization_ids',
            'organization_ids',
            'project_id',
            'current_project_id',
            'project_ids',
            'user_id',
            'actor_id',
            'scope',
            'permissions',
        ] as $field) {
            self::assertContains('prohibited', $rules[$field]);
        }
    }

    public function test_options_require_exact_as_of_and_period_boundaries(): void
    {
        $rules = (new AcceptedProductionReportOptionsRequest)->rules();

        self::assertSame(['required', 'string'], array_slice($rules['as_of'], 0, 2));
        self::assertSame(['required', 'string', 'date_format:Y-m-d'], $rules['period_from']);
        self::assertSame(
            ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            $rules['period_to'],
        );

        $parsed = ReportAsOfParser::parse('2026-08-06T14:15:16.123456+03:00');
        self::assertNotNull($parsed);
        self::assertSame('123456', $parsed->format('u'));
    }
}
