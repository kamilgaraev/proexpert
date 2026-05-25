<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\MultiOrganization;

use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingReportsController;
use App\BusinessModules\Core\MultiOrganization\Requests\HoldingReportRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function trans_message;

class HoldingReportsControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_summary_rejects_single_organization_without_server_error(): void
    {
        $organization = Organization::factory()->create([
            'organization_type' => 'single',
            'is_holding' => false,
        ]);
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $request = HoldingReportRequest::create(
            '/api/v1/landing/multi-organization/reports/projects-summary',
            'GET'
        );
        $request->attributes->set('current_organization_id', $organization->id);
        $request->setUserResolver(static fn () => $user);

        $response = app(HoldingReportsController::class)->projectsSummary($request);
        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(false, $payload['success']);
        $this->assertSame(trans_message('holding.access_denied'), $payload['message']);
    }
}
