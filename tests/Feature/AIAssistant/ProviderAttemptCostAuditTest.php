<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrClient;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrRenderer;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditQuote;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditReservationAllocation;
use App\Models\Credits\AICreditWallet;
use App\Models\File;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class ProviderAttemptCostAuditTest extends TestCase
{
    #[DataProvider('lateResults')]
    public function test_late_provider_result_is_internal_idempotent_and_does_not_change_final_charge(bool $cancelled, bool $successful): void
    {
        [$fixture, $credits, $reservation] = $this->reservation();
        if (! $cancelled) $credits->recordProviderCost($reservation, 2080, 'timeweb', 'openai/gpt-6-luna', 'completion', ['usage_key' => 'before-close'], true);
        $charge = $credits->finalize($reservation, 0, ! $cancelled, $cancelled);
        $before = $this->financialState();
        $metadata = ['usage_key' => 'late:'.$reservation->request_id, 'provider_usage_available' => true, 'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110];
        $cost = $credits->costMicroRub(100, 10, $reservation);
        $credits->recordProviderCost($reservation, $cost, 'timeweb', 'openai/gpt-6-luna', 'completion', $metadata, $successful);
        $credits->recordProviderCost($reservation, $cost, 'timeweb', 'openai/gpt-6-luna', 'completion', $metadata, $successful);
        self::assertSame($before, $this->financialState());
        self::assertSame($charge, $credits->finalize($reservation));
        $row = AICreditProviderUsage::query()->where('usage_key', $metadata['usage_key'])->sole();
        self::assertSame($cost, $row->cost_micro_rub);
        self::assertSame($successful, $row->is_successful);
        self::assertTrue($row->metadata['late_provider_result']);
        self::assertTrue($row->metadata['cost_available']);
        self::assertSame($cancelled ? 'cancelled' : 'finalized', $reservation->fresh()->status);
        try {
            $credits->recordProviderCost($reservation, $cost + 1, 'timeweb', 'openai/gpt-6-luna', 'completion', $metadata, $successful);
            self::fail('Conflicting receipt must be rejected');
        } catch (DomainException) {}
        self::assertSame($before, $this->financialState());
        self::assertSame(1, AICreditProviderUsage::query()->where('usage_key', $metadata['usage_key'])->count());
    }

    public static function lateResults(): array { return [[true, true], [true, false], [false, true], [false, false]]; }

    #[DataProvider('ocrFailures')]
    public function test_ocr_http_error_records_actual_or_unknown_cost_and_does_not_charge_or_store_text(?array $usage, bool $available): void
    {
        [$fixture, $credits, $reservation] = $this->reservation();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]);
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($content);
        $path = 'org-'.$fixture->organization->id.'/cost-audit/scan.png';
        $file = File::withoutEvents(fn () => File::query()->create(['organization_id' => $fixture->organization->id, 'fileable_type' => $project->getMorphClass(),
            'fileable_id' => $project->id, 'user_id' => $fixture->owner->id, 'name' => 'scan.png', 'original_name' => 'scan.png', 'path' => $path,
            'mime_type' => 'image/png', 'size' => strlen($content), 'disk' => 's3']));
        $document = AIAssistantDocument::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $project->id, 'storage_path' => $path, 'filename' => 'scan.png',
            'mime_type' => 'image/png', 'checksum' => hash('sha256', $content), 'size_bytes' => strlen($content), 'status' => 'ocr_approved',
            'coverage_status' => 'pending', 'metadata' => ['page_count' => 1], 'ocr_reservation_id' => $reservation->id,
            'ocr_approved_by' => $fixture->owner->id, 'ocr_approved_at' => now()]);
        config()->set('ai-assistant.llm.timeweb.api_key', 'synthetic-key');
        config()->set('ai-assistant.llm.timeweb.base_uri', 'https://api.timeweb.ai/v1');
        Http::fake(['*' => Http::response(['error' => ['message' => 'not journaled'], 'usage' => $usage], 500)]);
        $client = new AssistantDocumentOcrClient(new AssistantDocumentOcrRenderer, $credits);
        try {
            $client->recognize($document, $reservation, $content, function () use ($fixture, $project): void {
                self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadEntityContent($fixture->owner, $fixture->organization->id, 'project', $project->id));
            });
            self::fail('HTTP error expected');
        } catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_ocr_provider_failed', $exception->getMessage()); }
        $row = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->sole();
        self::assertSame($available, $row->metadata['cost_available']);
        self::assertFalse($row->is_successful);
        self::assertSame($reservation->request_id, $row->metadata['request_id']);
        self::assertSame($available ? $credits->costMicroRub((int) $usage['prompt_tokens'], (int) $usage['completion_tokens'], $reservation) : 0, $row->cost_micro_rub);
        self::assertSame(0, AIAssistantDocumentUnit::query()->where('document_id', $document->id)->count());
        self::assertSame(0, $credits->finalize($reservation, 0, false));
        self::assertSame(10000, AICreditWallet::query()->where('organization_id', $fixture->organization->id)->sole()->availableMinor());
    }

    public static function ocrFailures(): array { return [[['prompt_tokens' => 75, 'completion_tokens' => 0], true], [null, false], [['prompt_tokens' => 0, 'completion_tokens' => 0], true]]; }

    public function test_usage_tracker_persists_failed_known_zero_and_unknown_receipts_with_distinct_availability(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $tracker = new UsageTracker;
        $known = $tracker->recordUsage($fixture->organization->id, $fixture->owner->id, 'timeweb', 'openai/text-embedding-3-large', 'rag_query', 0, 0, 0,
            ['usage_key' => 'known-zero', 'is_successful' => false, 'provider_usage_available' => true]);
        $unknown = $tracker->recordUsage($fixture->organization->id, $fixture->owner->id, 'timeweb', 'openai/text-embedding-3-large', 'rag_query', 0, 0, 0,
            ['usage_key' => 'unknown', 'is_successful' => false, 'provider_usage_available' => false]);
        self::assertNotNull($known); self::assertNotNull($unknown);
        self::assertTrue($known->metadata['cost_available']); self::assertFalse($unknown->metadata['cost_available']);
        self::assertNull($tracker->recordUsage($fixture->organization->id, $fixture->owner->id, 'timeweb', 'openai/text-embedding-3-large', 'rag_query', 0, 0, 0));
    }

    private function reservation(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $credits = new AICreditService;
        $credits->grant($fixture->organization, 10000, 'purchase', null, 'audit:'.Str::uuid());
        $quote = AICreditQuote::query()->create(['public_id' => (string) Str::uuid(), 'organization_id' => $fixture->organization->id, 'user_id' => $fixture->owner->id,
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'profile' => 'ocr', 'price_version' => 1,
            'limits' => ['max_calls' => 1, 'input_tokens' => 8192, 'output_tokens' => 4096], 'pricing' => ['minimum_minor' => 50,
                'input_micro_rub_per_million' => 14000000, 'output_micro_rub_per_million' => 68000000,
                'unit_minor' => 50, 'unit_cost_micro_rub' => 90000], 'min_units_minor' => 50, 'max_units_minor' => 200, 'expires_at' => now()->addHour()]);
        $reservation = AICreditReservation::query()->create(['public_id' => (string) Str::uuid(), 'organization_id' => $fixture->organization->id,
            'user_id' => $fixture->owner->id, 'ai_credit_quote_id' => $quote->id, 'request_id' => (string) Str::uuid(), 'reserved_minor' => 200, 'consumed_minor' => 0, 'status' => 'reserved']);
        $lot = AICreditLot::query()->where('organization_id', $fixture->organization->id)->sole();
        $lot->decrement('remaining_minor', 200);
        AICreditWallet::query()->where('organization_id', $fixture->organization->id)->increment('reserved_minor', 200);
        AICreditReservationAllocation::query()->create(['ai_credit_reservation_id' => $reservation->id, 'ai_credit_lot_id' => $lot->id, 'reserved_minor' => 200, 'consumed_minor' => 0]);

        return [$fixture, $credits, $reservation];
    }

    private function financialState(): array
    {
        $state = [];
        foreach (['ai_credit_wallets', 'ai_credit_lots', 'ai_credit_reservations', 'ai_credit_reservation_allocations', 'ai_credit_ledger_entries'] as $table) $state[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();

        return $state;
    }
}
