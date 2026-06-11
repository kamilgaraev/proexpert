<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\BusinessModules\Features\Crm\Http\Requests\CrmListRequest;
use Tests\TestCase;

final class CrmListRequestTest extends TestCase
{
    public function test_normalizes_string_boolean_query_values_before_validation(): void
    {
        $request = CrmListRequest::create('/api/v1/admin/crm/companies', 'GET', [
            'archived' => 'false',
            'merged' => '1',
            'per_page' => '20',
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        $request->validateResolved();

        $validated = $request->validated();

        $this->assertFalse($validated['archived']);
        $this->assertTrue($validated['merged']);
    }
}
