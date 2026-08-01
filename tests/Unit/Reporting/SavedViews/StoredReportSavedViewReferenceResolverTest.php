<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SavedViews;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\SavedViews\StoredReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class StoredReportSavedViewReferenceResolverTest extends TestCase
{
    public function test_resolves_visible_view_to_content_bound_reference(): void
    {
        $view = $this->view();
        $resolver = new StoredReportSavedViewReferenceResolver(new InMemoryReferenceSavedViewStore($view));

        $reference = $resolver->resolve((new ReportExecutionContextBuilder())->build(), $view->id);

        self::assertSame($view->id, $reference->id);
        self::assertGreaterThan(0, $reference->revision);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $reference->hash->value);
    }

    public function test_assert_current_fails_closed_after_view_content_changes(): void
    {
        $store = new InMemoryReferenceSavedViewStore($this->view());
        $resolver = new StoredReportSavedViewReferenceResolver($store);
        $context = (new ReportExecutionContextBuilder())->build();
        $reference = $resolver->resolve($context, $store->view->id);
        $store->view = $this->view(name: 'Изменённое представление', updatedAt: '2026-07-26T00:00:01.000000Z');

        $this->expectException(ReportContractException::class);

        $resolver->assertCurrent($context, $reference);
    }

    public function test_needs_migration_view_cannot_be_resolved_for_a_run(): void
    {
        $view = $this->view(status: 'needs_migration');
        $resolver = new StoredReportSavedViewReferenceResolver(new InMemoryReferenceSavedViewStore($view));

        $this->expectException(ReportContractException::class);

        $resolver->resolve((new ReportExecutionContextBuilder())->build(), $view->id);
    }

    private function view(
        string $name = 'Моё представление',
        string $status = 'active',
        string $updatedAt = '2026-07-26T00:00:00.000000Z',
    ): ReportSavedView {
        return new ReportSavedView(
            '01J00000000000000000000001',
            'project_margin',
            '1.0.0',
            $name,
            'private',
            new ReportFilterSet(['project_id' => [10]]),
            [],
            new ReportWindowSort('project_id', ReportSortDirection::ASC),
            ['project_id', 'margin'],
            $status,
            false,
            new DateTimeImmutable('2026-07-25T00:00:00.000000Z'),
            new DateTimeImmutable($updatedAt),
        );
    }
}

final class InMemoryReferenceSavedViewStore implements ReportSavedViewStore
{
    public function __construct(public ReportSavedView $view)
    {
    }

    public function getVisible(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        return $this->view;
    }

    public function list(int $organizationId, int $ownerId, ReportSavedViewWindow $window): ReportSavedViewPage
    {
        throw new \LogicException('unused');
    }

    public function markNeedsMigrationLocked(int $organizationId, string $id): ReportSavedView
    {
        throw new \LogicException('unused');
    }

    public function create(int $organizationId, int $ownerId, CreateReportSavedViewData $data, string $contractVersion): ReportSavedView
    {
        throw new \LogicException('unused');
    }

    public function updateLocked(int $organizationId, int $ownerId, string $id, UpdateReportSavedViewData $data): ReportSavedView
    {
        throw new \LogicException('unused');
    }

    public function setDefaultLocked(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        throw new \LogicException('unused');
    }

    public function softDeleteLocked(int $organizationId, int $ownerId, string $id): void
    {
        throw new \LogicException('unused');
    }
}
