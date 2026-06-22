<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class BrigadeManagementControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_brigade_requests_are_created_listed_closed_and_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/brigades/requests', [
                'project_id' => $project->id,
                'title' => 'Нужна монолитная бригада',
                'description' => 'Работы на объекте в мае',
                'specialization_name' => 'Монолит',
                'city' => 'Казань',
                'team_size_min' => 4,
                'team_size_max' => 8,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.contractor_organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.status', BrigadeStatuses::REQUEST_OPEN);

        BrigadeRequest::query()->create([
            'contractor_organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'title' => 'Чужая заявка',
            'description' => 'Не должна попасть в список',
            'status' => BrigadeStatuses::REQUEST_OPEN,
            'published_at' => now(),
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/brigades/requests');

        $indexResponse->assertOk();
        $indexResponse->assertJsonCount(1, 'data');
        $indexResponse->assertJsonPath('data.0.id', $createResponse->json('data.id'));
        $indexResponse->assertJsonPath('data.0.responses_count', 0);

        $closeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/brigades/requests/' . $createResponse->json('data.id') . '/close');

        $closeResponse->assertOk();
        $closeResponse->assertJsonPath('data.status', BrigadeStatuses::REQUEST_CLOSED);

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/brigades/requests', [
                'project_id' => $foreignProject->id,
                'title' => 'Попытка чужого проекта',
                'description' => 'Заявка не должна создаться',
            ]);

        $foreignProjectResponse->assertStatus(422);
        $this->assertDatabaseMissing('brigade_requests', [
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $foreignProject->id,
        ]);
    }

    public function test_brigade_invitations_are_created_listed_cancelled_and_reject_foreign_project(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $brigade = $this->createBrigade('Каменная бригада');

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/brigades/invitations', [
                'brigade_id' => $brigade->id,
                'project_id' => $project->id,
                'message' => 'Приглашаем на объект',
                'starts_at' => '2026-05-20',
                'expires_at' => '2026-05-25',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.brigade_id', $brigade->id);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.contractor_organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.status', BrigadeStatuses::INVITATION_PENDING);

        BrigadeInvitation::query()->create([
            'brigade_id' => $brigade->id,
            'contractor_organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'message' => 'Чужое приглашение',
            'status' => BrigadeStatuses::INVITATION_PENDING,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/brigades/invitations');

        $indexResponse->assertOk();
        $indexResponse->assertJsonCount(1, 'data');
        $indexResponse->assertJsonPath('data.0.id', $createResponse->json('data.id'));

        $cancelResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/brigades/invitations/' . $createResponse->json('data.id') . '/cancel');

        $cancelResponse->assertOk();
        $cancelResponse->assertJsonPath('data.status', BrigadeStatuses::INVITATION_CANCELLED);

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/brigades/invitations', [
                'brigade_id' => $brigade->id,
                'project_id' => $foreignProject->id,
            ]);

        $foreignProjectResponse->assertStatus(422);
        $this->assertDatabaseMissing('brigade_invitations', [
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $foreignProject->id,
        ]);
    }

    public function test_approving_response_creates_scoped_assignment_and_assignment_list_filters_by_project(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $brigade = $this->createBrigade('Отделочная бригада');
        $request = BrigadeRequest::query()->create([
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'title' => 'Отделка',
            'description' => 'Нужна отделочная команда',
            'status' => BrigadeStatuses::REQUEST_OPEN,
            'published_at' => now(),
        ]);
        $response = BrigadeResponse::query()->create([
            'request_id' => $request->id,
            'brigade_id' => $brigade->id,
            'cover_message' => 'Готовы выйти',
            'status' => BrigadeStatuses::RESPONSE_PENDING,
        ]);
        BrigadeProjectAssignment::query()->create([
            'brigade_id' => $brigade->id,
            'project_id' => $otherProject->id,
            'contractor_organization_id' => $foreignContext->organization->id,
            'status' => BrigadeStatuses::ASSIGNMENT_PLANNED,
        ]);

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/brigades/requests/{$request->id}/responses/{$response->id}/approve");

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.response.status', BrigadeStatuses::RESPONSE_APPROVED);
        $approveResponse->assertJsonPath('data.assignment.project_id', $project->id);
        $approveResponse->assertJsonPath('data.assignment.contractor_organization_id', $context->organization->id);
        $this->assertDatabaseHas('brigade_requests', [
            'id' => $request->id,
            'status' => BrigadeStatuses::REQUEST_IN_REVIEW,
        ]);

        $assignmentsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/brigades/assignments?project_id={$project->id}");

        $assignmentsResponse->assertOk();
        $assignmentsResponse->assertJsonCount(1, 'data');
        $assignmentsResponse->assertJsonPath('data.0.project_id', $project->id);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson('/api/v1/admin/brigades/assignments/' . $approveResponse->json('data.assignment.id'), [
                'status' => BrigadeStatuses::ASSIGNMENT_ACTIVE,
                'notes' => 'Вышли на объект',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.status', BrigadeStatuses::ASSIGNMENT_ACTIVE);
        $updateResponse->assertJsonPath('data.notes', 'Вышли на объект');
    }

    public function test_brigade_request_detail_only_exposes_open_requests(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $brigade = $this->createBrigade('installation-brigade');

        $openRequest = BrigadeRequest::query()->create([
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'title' => 'Window installation',
            'description' => 'Open request for brigade response',
            'status' => BrigadeStatuses::REQUEST_OPEN,
            'published_at' => now(),
        ]);
        $closedRequest = BrigadeRequest::query()->create([
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'title' => 'Closed request',
            'description' => 'Closed request must not be exposed to brigade cabinet',
            'status' => BrigadeStatuses::REQUEST_CLOSED,
            'published_at' => now(),
        ]);

        $headers = $this->brigadeAuthHeaders($brigade);

        $this->withHeaders($headers)
            ->getJson("/api/v1/brigades/requests/{$openRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $openRequest->id);

        $this->withHeaders($headers)
            ->getJson("/api/v1/brigades/requests/{$closedRequest->id}")
            ->assertNotFound();
    }

    private function createBrigade(string $name): BrigadeProfile
    {
        $owner = User::factory()->create();

        return BrigadeProfile::query()->create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'description' => 'Проверенная бригада',
            'team_size' => 6,
            'contact_person' => 'Иван Петров',
            'contact_phone' => '+79990000000',
            'contact_email' => Str::random(8) . '@example.test',
            'regions' => ['Казань'],
            'availability_status' => BrigadeStatuses::AVAILABILITY_AVAILABLE,
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
    }

    private function brigadeAuthHeaders(BrigadeProfile $brigade): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::claims(['brigade_id' => $brigade->id])->fromUser($brigade->owner),
            'Accept' => 'application/json',
        ];
    }
}
