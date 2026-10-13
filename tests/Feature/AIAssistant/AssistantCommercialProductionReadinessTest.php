<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExecutiveReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProposalReader;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantCommercialProductionReadinessTest extends TestCase
{
    public function test_native_readiness_counts_are_complete_and_current_without_business_writes(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $fixture->organization->id; $actor = $fixture->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $args = ['project_id' => (int) $project->id];
        $proposals = app(AssistantProjectProposalReader::class); $production = app(AssistantProjectProductionReader::class); $executive = app(AssistantProjectExecutiveReader::class);
        self::assertSame(0, $proposals->read($actor, $org, $args)['data']['total']);
        self::assertSame(0, $production->read($actor, $org, $args)['data']['submitted']);
        self::assertSame(0, $executive->read($actor, $org, $args)['data']['total']);
        Model::withoutEvents(function () use ($org, $actor, $project, $other): void {
            foreach (range(1, 23) as $i) {
                CommercialProposal::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'created_by_user_id' => $actor->id,
                    'number' => 'PROP-'.$i, 'title' => 'Предложение', 'status' => 'sent', 'valid_until' => today()->subDay()]);
                ProductionLaborWorkOrder::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'created_by_user_id' => $actor->id,
                    'order_number' => 'ORDER-'.$i, 'title' => 'Наряд', 'status' => 'submitted', 'assignee_type' => 'employee', 'assignee_name' => 'Исполнитель']);
            }
            ProductionLaborWorkOrder::query()->create(['organization_id' => $org, 'project_id' => $other->id, 'created_by_user_id' => $actor->id,
                'order_number' => 'OTHER', 'title' => 'Чужой проект', 'status' => 'submitted', 'assignee_type' => 'employee']);
        });
        $proposal = $proposals->read($actor, $org, $args); $workOrders = $production->read($actor, $org, $args);
        self::assertSame(23, $proposal['data']['pending']); self::assertSame(23, $proposal['data']['expired_pending']); self::assertCount(20, $proposal['data']['examples']);
        self::assertSame(23, $workOrders['data']['submitted']); self::assertCount(20, $workOrders['data']['examples']);
        self::assertStringNotContainsString('Чужой проект', json_encode($workOrders, JSON_UNESCAPED_UNICODE));
        self::assertTrue($proposals->matchesReference($actor, $org, $proposal['source_refs'][0]));
        CommercialProposal::query()->where('number', 'PROP-1')->firstOrFail()->updateQuietly(['status' => 'accepted']);
        self::assertFalse($proposals->matchesReference($actor, $org, $proposal['source_refs'][0]));
        $set = Model::withoutEvents(fn () => ExecutiveDocumentSet::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'created_by' => $actor->id, 'set_number' => 'ED-READ', 'title' => 'Пустой комплект', 'status' => 'draft']));
        $result = $executive->read($actor, $org, $args);
        self::assertSame(1, $result['data']['not_transmitted']); self::assertSame(0, $result['data']['waiting_decision']);
        self::assertSame(0, $result['data']['examples'][0]['readiness']['documents_total']);
        self::assertFalse($result['data']['examples'][0]['readiness']['ready_to_transmit']);
        self::assertCount(2, $result['data']['examples'][0]['readiness']['blockers']);
        self::assertTrue($executive->matchesReference($actor, $org, $result['source_refs'][0]));
        $document = Model::withoutEvents(fn () => \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
            'organization_id' => $org, 'project_id' => $project->id, 'document_set_id' => $set->id, 'created_by' => $actor->id,
            'document_type' => 'other', 'document_number' => 'DOC-READ', 'title' => 'Документ', 'status' => 'approved', 'metadata' => ['capture_mode' => 'uploaded']]));
        $foreignVersion = Model::withoutEvents(fn () => \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion::query()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'document_id' => $document->id, 'uploaded_by' => $actor->id,
            'version_number' => '1', 'status' => 'approved', 'file_url' => 'PRIVATE_FOREIGN_FILE']));
        $foreignResult = $executive->read($actor, $org, $args);
        self::assertNull($foreignResult['data']['examples'][0]['readiness']);
        self::assertStringNotContainsString('PRIVATE_FOREIGN_FILE', json_encode($foreignResult, JSON_UNESCAPED_UNICODE));
        $foreignVersion->updateQuietly(['organization_id' => $org]);
        $foreignTransmittal = Model::withoutEvents(fn () => ExecutiveDocumentTransmittal::query()->create(['organization_id' => $fixture->foreignOrganization->id,
            'document_set_id' => $set->id, 'transmitted_by' => $actor->id, 'transmittal_number' => 'FOREIGN', 'status' => 'sent', 'transmitted_at' => now()]));
        $foreignTransmissionResult = $executive->read($actor, $org, $args);
        self::assertSame(0, $foreignTransmissionResult['data']['waiting_decision']);
        self::assertNull($foreignTransmissionResult['data']['examples'][0]['transmittal']);
        self::assertNull($foreignTransmissionResult['data']['examples'][0]['readiness']);
        $foreignTransmittal->delete();
        $transmittal = Model::withoutEvents(fn () => ExecutiveDocumentTransmittal::query()->create(['organization_id' => $org, 'document_set_id' => $set->id,
            'transmitted_by' => $actor->id, 'transmittal_number' => 'ED-SENT', 'status' => 'sent', 'transmitted_at' => now(), 'manifest' => ['oversized' => str_repeat('x', 1048577)]]));
        $set->updateQuietly(['status' => 'transmitted']);
        $large = $executive->read($actor, $org, $args);
        self::assertSame(1, $large['data']['waiting_decision']); self::assertNull($large['data']['examples'][0]['readiness']);
        self::assertFalse($executive->matchesReference($actor, $org, $result['source_refs'][0]));
        self::assertSame('sent', $transmittal->fresh()->status);
        $transmittal->updateQuietly(['manifest' => ['requirements' => []]]);
        $transmitted = $executive->read($actor, $org, $args);
        self::assertTrue($transmitted['data']['examples'][0]['readiness']['ready_to_transmit']);
        self::assertStringContainsString('Следующий шаг по переданному комплекту', $transmitted['server_formatted_answer']);
        self::assertStringNotContainsString('оформить передачу в карточке', $transmitted['server_formatted_answer']);
        self::assertSame('submitted', ProductionLaborWorkOrder::query()->where('order_number', 'ORDER-1')->value('status'));
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        foreach ([[$proposals, $proposal], [$production, $workOrders], [$executive, $large]] as [$reader, $proof]) {
            self::assertFalse($reader->matchesReference($actor, $org, $proof['source_refs'][0]));
        }
    }
}
