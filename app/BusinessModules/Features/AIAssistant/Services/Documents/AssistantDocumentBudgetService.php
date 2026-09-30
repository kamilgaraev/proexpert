<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\Jobs\ProcessAssistantDocumentOcr;
use App\Models\Credits\AICreditQuote;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantDocumentBudgetService
{
    public function __construct(private readonly AssistantDocumentService $documents) {}

    public function settings(User $actor, int $organizationId): AssistantDocumentSettings
    {
        $this->documents->assertOwner($actor, $organizationId);

        return AssistantDocumentSettings::query()->firstOrCreate(['organization_id' => $organizationId]);
    }

    public function approve(User $actor, int $organizationId, bool $enabled, int $limitMinor, string $scope): AssistantDocumentSettings
    {
        $this->documents->assertOwner($actor, $organizationId);
        if ($limitMinor < 0 || $limitMinor > 1_000_000_000 || ! in_array($scope, ['new', 'archive'], true)) {
            throw new RuntimeException('ai_assistant_document_budget_invalid');
        }

        return DB::transaction(function () use ($actor, $organizationId, $enabled, $limitMinor, $scope): AssistantDocumentSettings {
            AssistantDocumentSettings::query()->firstOrCreate(['organization_id' => $organizationId]);
            $settings = AssistantDocumentSettings::query()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
            if ($limitMinor < $settings->spent_minor + $settings->reserved_minor) {
                throw new RuntimeException('ai_assistant_document_budget_below_committed');
            }
            $settings->update(['background_ocr_enabled' => $enabled, 'limit_minor' => $limitMinor, 'scope' => $scope,
                'approved_by' => $actor->id, 'approved_at' => now()]);

            return $settings->refresh();
        });
    }

    public function authorizeBackground(AIAssistantDocument $document): bool
    {
        if ($document->status !== AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED) {
            return false;
        }

        return DB::transaction(function () use ($document): bool {
            $document = AIAssistantDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED) {
                return false;
            }
            $settings = AssistantDocumentSettings::query()->where('organization_id', $document->organization_id)->lockForUpdate()->first();
            if ($settings === null || ! $settings->background_ocr_enabled || $settings->approved_by === null) {
                return false;
            }
            $file = File::query()->find($document->file_id);
            if ($file === null || ($settings->scope === 'new' && ($settings->approved_at === null || $file->created_at->lt($settings->approved_at)))) {
                return false;
            }
            $actor = User::query()->find($settings->approved_by);
            if ($actor === null) {
                return false;
            }
            $quote = $this->documents->quoteOcr($actor, (int) $document->organization_id, $document);
            $required = (int) AICreditQuote::query()->where('public_id', $quote['quote_id'])->value('max_units_minor');
            if ($required > $settings->limit_minor - $settings->spent_minor - $settings->reserved_minor) {
                return false;
            }
            $approved = $this->documents->confirmOcr($actor, (int) $document->organization_id, $document, $quote['quote_id'], $quote['request_id']);
            $approved->update(['metadata' => array_merge($approved->metadata ?? [], ['background_budget_minor' => $required])]);
            $settings->increment('reserved_minor', $required);
            ProcessAssistantDocumentOcr::dispatch($document->id)->afterCommit();

            return true;
        });
    }
}
