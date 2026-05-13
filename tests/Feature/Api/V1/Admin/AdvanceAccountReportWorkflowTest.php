<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AdvanceAccountTransaction;
use App\Models\Organization;
use App\Models\PersonalFile;
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class AdvanceAccountReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_uses_document_period_and_current_organization_only(): void
    {
        Storage::fake('s3');
        Carbon::setTestNow('2026-05-13 12:00:00');

        $context = AdminApiTestContext::create();
        $user = $this->createOrganizationUser($context->organization, [
            'current_balance' => 1500,
            'total_issued' => 2500,
            'total_reported' => 1000,
        ]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $included = $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 2500,
            'document_date' => '2026-05-10',
            'created_at' => '2026-04-01 09:00:00',
        ]);
        $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'type' => AdvanceAccountTransaction::TYPE_EXPENSE,
            'amount' => 800,
            'document_date' => '2026-04-30',
            'created_at' => '2026-05-10 09:00:00',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = $this->createOrganizationUser($foreignOrganization, [
            'current_balance' => 9000,
            'total_issued' => 9000,
        ]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->createTransaction($foreignOrganization->id, $foreignUser->id, $foreignProject->id, [
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 9000,
            'document_date' => '2026-05-10',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/advance-accounts/summary?date_from=2026-05-01&date_to=2026-05-31');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.period.from', '2026-05-01');
        $response->assertJsonPath('data.period.to', '2026-05-31');
        $response->assertJsonPath('data.transaction_summary.issue.pending.count', 1);
        $response->assertJsonPath('data.transaction_summary.issue.pending.total_amount', 2500);
        $topUserIds = collect($response->json('data.top_users'))->pluck('id');
        $this->assertTrue($topUserIds->contains($user->id));
        $this->assertFalse($topUserIds->contains($foreignUser->id));

        $file = PersonalFile::query()
            ->where('user_id', $context->user->id)
            ->where('path', 'like', 'org-' . $context->organization->id . '/reports/%')
            ->where('filename', 'like', 'advance_account_summary_report_%.json')
            ->first();

        $this->assertInstanceOf(PersonalFile::class, $file);
        Storage::disk('s3')->assertExists($file->path);

        $reportFile = ReportFile::query()
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->where('path', $file->path)
            ->where('filename', $file->filename)
            ->first();

        $this->assertInstanceOf(ReportFile::class, $reportFile);

        Carbon::setTestNow();
    }

    public function test_user_and_project_reports_do_not_expose_foreign_entities(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = $this->createOrganizationUser($foreignOrganization);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $userResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/reports/advance-accounts/users/{$foreignUser->id}");

        $projectResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/reports/advance-accounts/projects/{$foreignProject->id}");

        $userResponse->assertNotFound();
        $userResponse->assertJsonPath('success', false);
        $projectResponse->assertNotFound();
        $projectResponse->assertJsonPath('success', false);
    }

    public function test_overdue_report_scopes_users_and_transactions_to_current_organization(): void
    {
        Storage::fake('s3');
        Carbon::setTestNow('2026-05-13 12:00:00');

        $context = AdminApiTestContext::create();
        $user = $this->createOrganizationUser($context->organization, [
            'current_balance' => 1100,
            'has_overdue_balance' => true,
            'last_transaction_at' => '2026-04-01 09:00:00',
        ]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 1100,
            'document_date' => '2026-04-01',
            'created_at' => '2026-04-01 09:00:00',
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = $this->createOrganizationUser($foreignOrganization, [
            'current_balance' => 9900,
            'has_overdue_balance' => true,
            'last_transaction_at' => '2026-04-01 09:00:00',
        ]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->createTransaction($foreignOrganization->id, $foreignUser->id, $foreignProject->id, [
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 9900,
            'document_date' => '2026-04-01',
            'created_at' => '2026-04-01 09:00:00',
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/advance-accounts/overdue?overdue_days=30');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.summary.user_count', 1);
        $response->assertJsonPath('data.summary.transaction_count', 1);
        $response->assertJsonPath('data.summary.total_overdue_amount', 1100);
        $response->assertJsonPath('data.users_with_overdue_balance.0.id', $user->id);
        $response->assertJsonPath('data.overdue_transactions.0.user.id', $user->id);

        Carbon::setTestNow();
    }

    public function test_export_route_streams_report_and_rejects_unknown_format_with_admin_response(): void
    {
        $context = AdminApiTestContext::create();
        $user = $this->createOrganizationUser($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->createTransaction($context->organization->id, $user->id, $project->id, [
            'amount' => 1200,
            'document_date' => '2026-05-01',
        ]);

        $csvResponse = $this->withHeaders($context->authHeaders())
            ->get('/api/v1/admin/reports/advance-accounts/export/csv?report_type=summary&date_from=2026-05-01&date_to=2026-05-31');

        $csvResponse->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', (string) $csvResponse->headers->get('content-disposition'));

        $invalidResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/reports/advance-accounts/export/pdf?report_type=summary');

        $invalidResponse->assertStatus(422);
        $invalidResponse->assertJsonPath('success', false);
    }

    private function createOrganizationUser(Organization $organization, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ], $overrides));

        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
    }

    private function createTransaction(int $organizationId, int $userId, int $projectId, array $overrides = []): AdvanceAccountTransaction
    {
        $transaction = AdvanceAccountTransaction::query()->create(array_merge([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'type' => AdvanceAccountTransaction::TYPE_ISSUE,
            'amount' => 100,
            'description' => 'Advance',
            'document_date' => '2026-05-01',
            'balance_after' => 100,
            'reporting_status' => AdvanceAccountTransaction::STATUS_PENDING,
        ], $overrides));

        if (array_key_exists('created_at', $overrides) || array_key_exists('updated_at', $overrides)) {
            $transaction->forceFill([
                'created_at' => $overrides['created_at'] ?? $transaction->created_at,
                'updated_at' => $overrides['updated_at'] ?? $overrides['created_at'] ?? $transaction->updated_at,
            ])->saveQuietly();
        }

        return $transaction;
    }
}
