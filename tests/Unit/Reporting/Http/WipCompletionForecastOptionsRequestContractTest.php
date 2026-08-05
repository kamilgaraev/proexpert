<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\WipCompletionForecastReportOptionsRequest;
use PHPUnit\Framework\TestCase;

final class WipCompletionForecastOptionsRequestContractTest extends TestCase
{
    public function test_client_cannot_replace_server_owned_context(): void
    {
        $rules = (new WipCompletionForecastReportOptionsRequest)->rules();

        foreach (['organization_id', 'project_id', 'current_project_id', 'user_id', 'actor_id', 'scope', 'permissions'] as $field) {
            self::assertContains('prohibited', $rules[$field]);
        }
    }
}
