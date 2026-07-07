<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SafetyBriefingSignatureWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_briefing_creation_keeps_participants_unsigned_until_explicit_signature(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $worker = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($worker->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/briefings', [
                'project_id' => $project->id,
                'title' => 'Пятиминутка перед огневыми работами',
                'briefing_type' => 'toolbox',
                'conducted_at' => now()->toIso8601String(),
                'topics' => ['Огневые работы', 'Средства тушения'],
                'participants' => [
                    ['user_id' => $worker->id, 'role_name' => 'Монтажник'],
                    ['external_name' => 'Петр Субподрядчик', 'company_name' => 'Субподрядчик', 'role_name' => 'Сварщик'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_signatures')
            ->assertJsonPath('data.signature_summary.total', 2)
            ->assertJsonPath('data.signature_summary.pending', 2)
            ->assertJsonPath('data.signature_summary.signed', 0);

        $participants = collect($response->json('data.participants'));
        self::assertSame(['pending', 'pending'], $participants->pluck('signature_status')->values()->all());
        self::assertTrue($participants->pluck('signed_at')->every(static fn ($value): bool => $value === null));
    }

    public function test_briefing_can_be_completed_after_signatures_and_absence_marks(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $worker = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($worker->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $created = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/briefings', [
                'project_id' => $project->id,
                'title' => 'Целевой инструктаж перед работами на высоте',
                'briefing_type' => 'target',
                'conducted_at' => now()->toIso8601String(),
                'participants' => [
                    ['user_id' => $worker->id, 'role_name' => 'Монтажник'],
                    ['external_name' => 'Петр Субподрядчик', 'company_name' => 'Субподрядчик', 'role_name' => 'Сварщик'],
                ],
            ]);

        $created->assertCreated();
        $briefingId = (int) $created->json('data.id');
        $participants = collect($created->json('data.participants'));
        $workerParticipantId = (int) $participants->firstWhere('user_id', $worker->id)['id'];
        $externalParticipantId = (int) $participants->firstWhere('external_name', 'Петр Субподрядчик')['id'];

        $signed = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/briefings/{$briefingId}/participants/{$workerParticipantId}/sign", [
                'signature_method' => 'admin',
            ]);

        $signed->assertOk()
            ->assertJsonPath('data.signature_summary.signed', 1)
            ->assertJsonPath('data.signature_summary.pending', 1);
        self::assertSame('signed', collect($signed->json('data.participants'))->firstWhere('id', $workerParticipantId)['signature_status']);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/briefings/{$briefingId}/complete")
            ->assertStatus(422);

        $absent = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/briefings/{$briefingId}/participants/{$externalParticipantId}/mark-absent", [
                'absence_reason' => 'Не вышел на смену',
            ]);

        $absent->assertOk()
            ->assertJsonPath('data.signature_summary.absent', 1)
            ->assertJsonPath('data.signature_summary.pending', 0);

        $completed = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/briefings/{$briefingId}/complete");

        $completed->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_by_user_id', $context->user->id)
            ->assertJsonPath('data.available_actions.0', 'open_journal');

        $journal = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/documents/briefing-journal/draft', [
                'project_id' => $project->id,
                'briefing_type' => 'target',
            ]);

        $journal->assertOk()
            ->assertJsonPath('data.sections.0.rows.0.title', 'Целевой инструктаж перед работами на высоте');
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'safety-management',
                    'project-management',
                    'file-management',
                ], true)
            );
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
