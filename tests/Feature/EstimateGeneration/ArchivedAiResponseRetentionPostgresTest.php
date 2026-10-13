<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\PruneArchivedAiPhysicalResponses;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class ArchivedAiResponseRetentionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_only_old_archived_published_responses_are_pruned_and_money_and_replay_identity_survive(): void
    {
        $session = $this->makeSession('archived');
        $attempt = $this->attempt($session);
        $this->role($session, $attempt);
        $before = DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt)->first();
        $role = DB::table('estimate_generation_ai_role_runs')->where('physical_attempt_id', $attempt)->first();
        self::assertSame(1, app(PruneArchivedAiPhysicalResponses::class)->handle());
        $after = DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt)->first();
        $remaining = json_decode($after->response_payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['response_retention'], array_keys($remaining));
        self::assertSame('archived_published_response', $remaining['response_retention']['reason']);
        self::assertStringNotContainsString('PRIVATE_CANARY', $after->response_payload);
        foreach (['attempt_id', 'request_fingerprint', 'state', 'usage_recorded', 'price_snapshot', 'duration_ms', 'reported_model', 'response_received_at', 'updated_at'] as $field) {
            self::assertSame($before->{$field}, $after->{$field}, $field);
        }
        self::assertEquals($role, DB::table('estimate_generation_ai_role_runs')->where('physical_attempt_id', $attempt)->first());
        self::assertSame(0, app(PruneArchivedAiPhysicalResponses::class)->handle());
        $store = app(DurableAiPhysicalResponseStore::class);
        foreach (['wire', 'parsed'] as $reader) {
            try {
                if ($reader === 'wire') {
                    $store->replayWire($session->organization_id, $session->project_id, $session->id, $attempt, hash('sha256', $attempt));
                } else {
                    $store->replay($attempt, hash('sha256', $attempt));
                }
                self::fail('A removed response must stop replay before transport.');
            } catch (AiWireNotStarted $error) {
                self::assertSame('text_ai_response_retention_expired', $error->getMessage());
            }
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_recoverable_sessions_recent_archives_unpublished_roles_and_unrecorded_attempts_are_preserved(): void
    {
        $ids = [];
        foreach (['draft', 'failed', 'cancelled', 'applied'] as $status) {
            $session = $this->makeSession($status);
            $ids[] = $attempt = $this->attempt($session);
            $this->role($session, $attempt);
        }
        $recent = $this->makeSession('archived');
        $recent->forceFill(['updated_at' => now()])->saveQuietly();
        $ids[] = $attempt = $this->attempt($recent);
        $this->role($recent, $attempt);
        $unpublished = $this->makeSession('archived');
        $ids[] = $this->attempt($unpublished);
        $running = $this->makeSession('archived');
        $ids[] = $attempt = $this->attempt($running);
        $this->role($running, $attempt, 'running');
        $unrecorded = $this->makeSession('archived');
        $ids[] = $attempt = $this->attempt($unrecorded, false);
        $this->role($unrecorded, $attempt);
        self::assertSame(0, app(PruneArchivedAiPhysicalResponses::class)->handle());
        foreach ($ids as $id) {
            self::assertStringContainsString('PRIVATE_CANARY', DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $id)->value('response_payload'));
        }
    }

    public function test_vision_body_waits_for_the_exact_published_output_and_keeps_the_original_document(): void
    {
        $session = $this->makeSession('archived');
        $source = 'sha256:'.hash('sha256', 'private original');
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'session_id' => $session->id, 'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'user_id' => $session->user_id, 'filename' => 'private.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.pdf',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'file_size_bytes' => 16, 'status' => 'ready',
        ]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'session_id' => $session->id, 'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'document_id' => $document->id, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OriginalDocumentArtifactLocator::forUnit($document,
                \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::PdfPage, 1, $source, ['page' => 1]),
            'metadata' => ['processing_attempt_id' => (string) Str::uuid()],
        ]);
        $attempt = $this->attempt($session);
        DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt)->update(['unit_id' => $unit->id,
            'document_id' => $document->id]);
        $original = $document->refresh()->getAttributes();
        self::assertSame(0, app(PruneArchivedAiPhysicalResponses::class)->handle());
        $output = 'sha256:'.hash('sha256', 'published output');
        $unit->forceFill(['status' => 'completed', 'output_version' => $output, 'output_count' => 1, 'completed_at' => now()])->saveQuietly();
        $page = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage::query()->create([
            'session_id' => $session->id, 'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'document_id' => $document->id, 'processing_unit_id' => $unit->id, 'page_number' => 1, 'source_version' => $source,
            'output_version' => 'sha256:'.hash('sha256', 'old output'), 'status' => 'ready', 'normalized_payload' => ['schema_version' => 3],
        ]);
        self::assertSame(0, app(PruneArchivedAiPhysicalResponses::class)->handle());
        $page->forceFill(['output_version' => $output])->saveQuietly();
        self::assertSame(1, app(PruneArchivedAiPhysicalResponses::class)->handle());
        self::assertSame($original, $document->fresh()->getAttributes());
        self::assertSame($output, $page->fresh()->output_version);
    }

    private function makeSession(string $status): EstimateGenerationSession
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => User::factory()->create()->id,
            'status' => $status, 'state_version' => 0, 'input_payload' => []]);
        DB::table('estimate_generation_sessions')->where('id', $session->id)->update(['updated_at' => now()->subDays(8)]);

        return $session->refresh();
    }

    private function attempt(EstimateGenerationSession $session, bool $recorded = true): string
    {
        $id = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $id,
            'request_fingerprint' => hash('sha256', $id), 'logical_request_fingerprint' => hash('sha256', $id),
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'state' => $recorded ? 'completed' : 'response_received', 'usage_recorded' => $recorded, 'status' => 'succeeded',
            'http_code' => 200, 'duration_ms' => 42, 'reported_model' => 'openai/gpt-6-luna',
            'price_snapshot' => json_encode(['currency' => 'RUB', 'version' => 'fixture']),
            'response_payload' => json_encode(['wire_response' => ['text' => 'PRIVATE_CANARY', 'model' => 'openai/gpt-6-luna'],
                'parsed_response' => ['work_intents' => []], 'provider_response' => ['input_tokens' => 100]]),
            'response_received_at' => now()->subDays(8), 'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)]);

        return $id;
    }

    private function role(EstimateGenerationSession $session, string $attempt, string $status = 'completed'): void
    {
        DB::table('estimate_generation_ai_role_runs')->insert(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'subject_type' => 'session', 'subject_id' => (string) $session->id,
            'subject_version' => 'fixture', 'role' => 'composer', 'status' => $status, 'model' => 'openai/gpt-6-luna',
            'prompt_contract_version' => 'fixture', 'input_fingerprint' => hash('sha256', $attempt),
            'identity_fingerprint' => hash('sha256', 'role'.$attempt), 'physical_attempt_id' => $attempt,
            'result_payload' => $status === 'completed' ? json_encode(['work_intents' => []]) : null,
            'owner_uuid' => $status === 'running' ? (string) Str::uuid() : null,
            'lease_expires_at' => $status === 'running' ? now()->addMinute() : null,
            'completed_at' => $status === 'completed' ? now()->subDays(8) : null,
            'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)]);
    }
}
