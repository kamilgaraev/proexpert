<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelReviewReadContractTest extends TestCase
{
    #[Test]
    public function review_read_route_is_versioned_scoped_and_view_authorized(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertStringContainsString("/{session}/project-model/review", $routes);
        self::assertStringContainsString("authorize:estimate_generation.view,project,project", $routes);
        self::assertStringContainsString('project-model.review.show', $routes);
    }

    #[Test]
    public function read_contract_has_bounded_filters_cursor_and_stale_version_semantics(): void
    {
        $request = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Http/Requests/ShowEstimateGenerationProjectModelReviewRequest.php');
        $service = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Http/Presentation/ProjectModelReviewPayloadService.php');

        foreach (['document_id', 'sheet_id', 'entity_kind', 'status', 'needs_action', 'query', 'cursor', 'per_page', 'state_version'] as $filter) self::assertStringContainsString("'{$filter}'", $request);
        self::assertStringContainsString("'max:100'", $request);
        self::assertStringContainsString("'content_version'", $service);
        self::assertStringContainsString("'stale'", $service);
        self::assertStringContainsString('encodeCursor', $service);
        self::assertStringContainsString('source_version', $service);
    }

    #[Test]
    public function payload_exposes_actionable_graph_without_private_storage_or_raw_locator(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Http/Presentation/ProjectModelReviewPayloadService.php');

        foreach (['documents', 'sheets', 'entities', 'assertions', 'candidates', 'current_value', 'dependency_impacts', 'viewer_anchors', 'latest_correction', 'conflicts', 'preview_url'] as $field) self::assertStringContainsString("'{$field}'", $service);
        self::assertStringContainsString("unset(\$payload['storage_path']", $service);
        self::assertStringContainsString("\$payload['raw_locator']", $service);
        self::assertStringContainsString('EstimateGenerationDocumentPreviewService', $service);
        self::assertStringNotContainsString("'storage_path' =>", $service);
        self::assertStringNotContainsString("'raw_locator' =>", $service);
    }
}
