<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowReadinessGuard;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateAvailability;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

// Regression: ISSUE-030 — отправка документа без маршрута выглядела доступной.
// Found by /qa on 2026-08-29
// Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
final class LegalWorkflowRouteAvailabilityRegressionTest extends TestCase
{
    public function test_submit_action_is_disabled_when_approval_route_is_not_configured(): void
    {
        $document = $this->readyDocument();
        $actor = new RouteAvailabilityTestUser;
        $actor->forceFill(['id' => 8, 'current_organization_id' => 15]);
        $resolver = new LegalWorkflowActionResolver(
            new LegalWorkflowAuthorization,
            new LegalWorkflowActorResolver,
            readiness: new LegalDocumentWorkflowReadinessGuard(
                new LegalDocumentProfileRegistry(
                    static fn (): ?array => null,
                    require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
                ),
                new LegalDocumentProfileValidator,
            ),
            templateAvailability: new FixedTemplateAvailability(false),
        );

        $submit = $resolver->forMany($actor, collect([$document]))[42]->action('submit');

        self::assertFalse($submit->enabled);
        self::assertContains('legal_archive.workflow.blockers.route_not_configured', $submit->blockers);
    }

    public function test_frozen_document_does_not_offer_resubmission_or_a_new_version(): void
    {
        $actor = new RouteAvailabilityTestUser;
        $actor->forceFill(['id' => 8, 'current_organization_id' => 15]);
        $resolver = new LegalWorkflowActionResolver(
            new LegalWorkflowAuthorization,
            new LegalWorkflowActorResolver,
            readiness: new LegalDocumentWorkflowReadinessGuard(
                new LegalDocumentProfileRegistry(
                    static fn (): ?array => null,
                    require dirname(__DIR__, 3).'/config/legal-document-profiles.php',
                ),
                new LegalDocumentProfileValidator,
            ),
            templateAvailability: new FixedTemplateAvailability(true),
        );

        foreach (['approved', 'signing', 'partially_signed', 'signed', 'effective', 'active', 'completed', 'archived'] as $state) {
            $document = $this->readyDocument();
            $document->forceFill(['lifecycle_status' => $state]);
            $submit = $resolver->forMany($actor, collect([$document]))[42]->action('submit');

            self::assertFalse($submit->enabled, $state);
            self::assertContains('legal_archive.workflow.blockers.document_frozen', $submit->blockers, $state);
            self::assertNotContains('legal_archive.workflow.blockers.new_version_required', $submit->blockers, $state);
        }

        $document = $this->readyDocument();
        $document->forceFill(['approval_status' => 'approved', 'lifecycle_status' => null]);
        $instance = new \App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
        $instance->forceFill(['id' => 9, 'status' => 'approved', 'document_version_id' => 73, 'document_content_hash' => str_repeat('a', 64)]);
        $instance->setRelation('steps', collect());
        $document->setRelation('latestWorkflowInstance', $instance);
        $submit = $resolver->forMany($actor, collect([$document]))[42]->action('submit');

        self::assertFalse($submit->enabled);
        self::assertContains('legal_archive.workflow.blockers.document_frozen', $submit->blockers);
        self::assertNotContains('legal_archive.workflow.blockers.new_version_required', $submit->blockers);

        $document->forceFill(['approval_status' => 'returned', 'lifecycle_status' => 'draft']);
        $instance->forceFill(['status' => 'returned']);
        $submit = $resolver->forMany($actor, collect([$document]))[42]->action('submit');

        self::assertFalse($submit->enabled);
        self::assertContains('legal_archive.workflow.blockers.new_version_required', $submit->blockers);
        self::assertNotContains('legal_archive.workflow.blockers.document_frozen', $submit->blockers);
    }

    private function readyDocument(): LegalArchiveDocument
    {
        $document = new LegalArchiveDocument;
        $document->forceFill([
            'id' => 42,
            'organization_id' => 15,
            'primary_project_id' => 7,
            'type_profile_code' => '',
            'structured_fields' => [],
            'current_primary_version_id' => 73,
            'open_blocking_comments_count' => 0,
        ]);
        $version = new LegalArchiveDocumentVersion;
        $version->forceFill([
            'id' => 73,
            'document_id' => 42,
            'organization_id' => 15,
            'is_current' => true,
            'processing_status' => 'ready',
            'content_hash' => str_repeat('a', 64),
        ]);
        $document->setRelation('currentVersion', $version);
        $document->setRelation('latestWorkflowInstance', null);

        return $document;
    }
}

final class FixedTemplateAvailability implements LegalWorkflowTemplateAvailability
{
    public function __construct(private readonly bool $available) {}

    public function isAvailable(LegalArchiveDocument $document): bool
    {
        return $this->available;
    }

    public function forMany(Collection $documents): array
    {
        return $documents->mapWithKeys(
            fn (LegalArchiveDocument $document): array => [(int) $document->id => $this->available],
        )->all();
    }
}

final class RouteAvailabilityTestUser extends User
{
    public function hasPermission(string $permission, ?array $context = null): bool
    {
        return true;
    }
}
