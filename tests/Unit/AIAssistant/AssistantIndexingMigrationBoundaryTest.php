<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver;
use App\BusinessModules\Features\AIAssistant\Observers\EstimateRagIndexObserver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Estimate;
use App\Models\File;
use App\Models\Project;
use App\Observers\AssistantDocumentIndexObserver;
use App\Observers\AssistantEntityFileObserver;
use PHPUnit\Framework\TestCase;

final class AssistantIndexingMigrationBoundaryTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_schema_changes_do_not_enqueue_or_query_indexing_and_normal_saves_resume(): void
    {
        $state = new AssistantIndexingState;
        app()->instance(AssistantIndexingState::class, $state);
        $coordinator = $this->getMockBuilder(RagIndexingCoordinator::class)->disableOriginalConstructor()->onlyMethods(['queueEntity'])->getMock();
        $coordinator->expects(self::once())->method('queueEntity')->with(7, 12, 'project', 'project', 12)->willReturn(new RagIndexRun);
        app()->instance(RagIndexingCoordinator::class, $coordinator);
        $project = (new Project)->forceFill(['id' => 12, 'organization_id' => 7]);
        $observer = new AssistantRagEntityObserver;
        $state->beginMigration();
        $state->beginMigration();
        $observer->saved($project);
        (new EstimateRagIndexObserver)->saved((new Estimate)->forceFill(['id' => 2, 'organization_id' => 7]));
        (new AssistantEntityFileObserver)->saved((new File)->forceFill(['id' => 3, 'disk' => 's3']));
        (new AssistantEntityFileObserver)->deleted(new File);
        (new AssistantDocumentIndexObserver)->deleted(new AIAssistantDocument);
        $state->endMigration();
        $observer->saved($project);
        $state->endMigration();
        $observer->saved($project);
    }
}
