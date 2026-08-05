<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ProjectEvmControlReportOptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectEvmControlOptionsRequestContractTest extends TestCase
{
    public function test_client_cannot_replace_server_owned_context(): void
    {
        $rules = (new ProjectEvmControlReportOptionsRequest)->rules();

        foreach (['organization_id', 'project_id', 'current_project_id', 'user_id', 'actor_id', 'scope', 'permissions'] as $field) {
            self::assertContains('prohibited', $rules[$field]);
        }
    }

    public function test_options_require_the_same_exact_as_of_contract_as_runtime(): void
    {
        $rules = (new ProjectEvmControlReportOptionsRequest)->rules();

        self::assertSame(['required', 'string'], array_slice($rules['as_of'], 0, 2));
        $parsed = ReportAsOfParser::parse('2026-08-05T14:15:16.123456+03:00');
        self::assertNotNull($parsed);
        self::assertSame('123456', $parsed->format('u'));
    }

    #[DataProvider('invalidAsOfProvider')]
    public function test_invalid_as_of_is_rejected_by_shared_parser(mixed $value): void
    {
        self::assertNull(ReportAsOfParser::parse($value));
    }

    public static function invalidAsOfProvider(): array
    {
        return [
            'date only' => ['2026-08-05'],
            'timezone missing' => ['2026-08-05T14:15:16'],
            'invalid day' => ['2026-02-30T14:15:16Z'],
            'too many fractions' => ['2026-08-05T14:15:16.1234567Z'],
            'not string' => [123],
        ];
    }
}
