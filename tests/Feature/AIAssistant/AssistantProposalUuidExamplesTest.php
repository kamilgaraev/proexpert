<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProposalReader;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantProposalUuidExamplesTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Model::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false,
        ]));
        $this->actingAs($this->fixture->owner);
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_real_proposal_uuids_remain_distinct_in_data_and_the_visible_answer(): void
    {
        $first = $this->proposal('00000001-0000-4000-8000-000000000001', 'PROP-UUID-1');
        $second = $this->proposal('00000001-0000-4000-8000-000000000002', 'PROP-UUID-2');
        Queue::fake();
        $reader = app(AssistantProjectProposalReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame(2, $read['data']['total']);
        self::assertSame([$first->id, $second->id], array_column($read['data']['examples'], 'id'));
        foreach ([$first, $second] as $proposal) {
            self::assertStringContainsString('#'.$proposal->id.' ', $read['server_formatted_answer']);
            self::assertSame('approved', $proposal->fresh()->getRawOriginal('status'));
        }
        self::assertStringNotContainsString('- #1 ', $read['server_formatted_answer']);
        self::assertTrue($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        Queue::assertNothingPushed();
    }

    public function test_replacing_a_visible_uuid_invalidates_the_source_even_when_other_fields_match(): void
    {
        $first = $this->proposal('00000001-0000-4000-8000-000000000003', 'PROP-IDENTITY');
        $reader = app(AssistantProjectProposalReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        Model::withoutEvents(fn () => $first->forceDelete());
        $replacement = $this->proposal('00000001-0000-4000-8000-000000000004', 'PROP-IDENTITY');
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        self::assertNull($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        $fresh = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame($replacement->id, $fresh['data']['examples'][0]['id']);
        self::assertNotSame($read['source_refs'][0]['source_version'], $fresh['source_refs'][0]['source_version']);
    }

    private function proposal(string $id, string $number): CommercialProposal
    {
        return Model::withoutEvents(fn () => CommercialProposal::query()->forceCreate([
            'id' => $id, 'organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'created_by_user_id' => $this->fixture->owner->id,
            'number' => $number, 'title' => 'Предложение', 'status' => 'approved', 'valid_until' => '2026-11-01',
        ]));
    }
}
