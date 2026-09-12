<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueSubscriptionsTest extends TestCase
{
    public static function subscriptions(): array
    {
        return [
            'PIR without quality' => [true, false, true],
            'both interfaces' => [true, true, true],
            'quality only' => [false, true, true],
            'neither interface' => [false, false, true],
            'PIR without project membership' => [true, true, false],
        ];
    }

    #[DataProvider('subscriptions')]
    public function test_pir_issue_read_requires_its_subscription_and_project_membership(bool $pir, bool $quality, bool $member): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        if ($member) {
            $project->users()->attach($context->user->id, ['role' => 'project_manager', 'is_active' => true]);
        }
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(
            static fn (int $organizationId, string $module): bool => match ($module) {
                'design-management' => $pir,
                'quality-control' => $quality,
                default => false,
            },
        );
        $issue = QualityDefect::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'kind' => 'project', 'created_by' => $context->user->id, 'defect_number' => 'PIR-SUBSCRIPTION',
            'title' => 'Проектное замечание', 'severity' => 'major', 'status' => 'open', 'metadata' => [],
        ]);
        if (! $pir || ! $member) {
            $this->expectException(DomainException::class);
        }

        $result = app(DesignProjectIssueService::class)->list($context->user, $context->organization->id, $project->id);

        self::assertSame([$issue->id], $result->modelKeys());
    }
}
