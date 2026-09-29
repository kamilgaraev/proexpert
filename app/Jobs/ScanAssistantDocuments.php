<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\Models\File;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ScanAssistantDocuments implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $uniqueFor = 300;

    public function __construct(public readonly int $organizationId) {}
    public function uniqueId(): string { return 'assistant-document-scan:'.$this->organizationId; }

    public function handle(): void
    {
        Cache::lock('assistant-document-scan:'.$this->organizationId, 300)->block(5, function (): void {
            if (! Organization::query()->whereKey($this->organizationId)->exists()) {
                return;
            }
            AssistantDocumentSettings::query()->firstOrCreate(['organization_id' => $this->organizationId]);
            DB::transaction(function (): void {
                $settings = AssistantDocumentSettings::query()->where('organization_id', $this->organizationId)->lockForUpdate()->firstOrFail();
                app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeDocumentDiscovery::class)
                    ->discover($this->organizationId, $settings->approved_by === null ? null : (int) $settings->approved_by);
                $files = File::query()->where('organization_id', $this->organizationId)->where('disk', 's3')->where('id', '>', $settings->last_file_id)->orderBy('id')->limit(50)->get(['id']);
                foreach ($files as $file) {
                    RegisterAssistantEntityFile::dispatch((int) $file->id)->afterCommit();
                }
                $settings->update(['last_file_id' => $files->last()?->id ?? $settings->last_file_id,
                    'scanned_count' => $settings->scanned_count + $files->count(), 'scan_completed_at' => $files->count() < 50 ? now() : null]);
                if ($settings->background_ocr_enabled) {
                    $pending = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::query()
                        ->where('organization_id', $this->organizationId)->where('status', 'ocr_quote_required')
                        ->whereNull('last_error')->orderBy('id')->limit(50)->get(['id']);
                    foreach ($pending as $document) {
                        AuthorizeBackgroundAssistantDocumentOcr::dispatch((int) $document->id)->afterCommit();
                    }
                }
                $stalled = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::query()
                    ->where('organization_id', $this->organizationId)->where('status', 'ocr_approved')
                    ->where('updated_at', '<', now()->subMinutes(25))->orderBy('id')->limit(50)->get(['id']);
                foreach ($stalled as $document) {
                    ProcessAssistantDocumentOcr::dispatch((int) $document->id)->afterCommit();
                }
            });
        });
    }
}
