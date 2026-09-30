<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AssistantMemory;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\ChatAttachment;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\ReportFile;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssistantRetentionService
{
    public function __construct(private readonly FileService $files) {}

    public function preview(int $days = 90): array
    {
        $cutoff = $this->cutoff($days);
        $expired = $this->expiredConversations($cutoff);
        $ids = (clone $expired)->select('id');
        $counts = $this->emptyCounts();
        $counts['conversations'] = (clone $expired)->count();
        $counts['messages'] = DB::table('ai_messages')->whereIn('conversation_id', clone $ids)->count();
        $counts['summaries'] = DB::table('ai_conversation_summaries')->whereIn('conversation_id', clone $ids)->count();
        $counts['attachments'] = ChatAttachment::query()->whereIn('conversation_id', clone $ids)->count();
        $counts['attachments'] += ChatAttachment::query()->whereNull('message_id')->where('created_at', '<=', now()->subDay())->where(fn (Builder $q) => $q->whereNull('conversation_id')->orWhereNotIn('conversation_id', clone $ids))->count();
        $counts['files'] += $counts['attachments'];
        $counts['memories'] = AssistantMemory::query()->where(fn (Builder $q) => $q->whereIn('conversation_id', clone $ids)
            ->orWhere('expires_at', '<=', now())->orWhere('last_used_at', '<=', $cutoff))->count();
        foreach ($expired->orderBy('id')->cursor() as $conversation) {
            foreach ($this->reportCandidates($conversation, $cutoff) as $report) {
                $counts['reports']++;
                $counts['files']++;
            }
            $documents = $this->documentCandidates($conversation)->count();
            $counts['documents'] += $documents;
            $counts['files'] += $documents;
        }

        return ['mode' => 'preview', 'days' => $days, 'cutoff' => $cutoff->toISOString()] + $counts;
    }

    public function purge(int $days = 90): array
    {
        $cutoff = $this->cutoff($days);
        $counts = $this->emptyCounts();
        $this->expiredConversations($cutoff)->orderBy('id')->chunkById(100, function ($items) use ($cutoff, &$counts): void {
            foreach ($items as $conversation) {
                $deleted = [];
                if ($this->deleteConversation($conversation, $cutoff, $deleted)) {
                    foreach ($deleted as $key => $count) {
                        $counts[$key] += $count;
                    }
                }
            }
        });
        $counts['memories'] += AssistantMemory::query()->where(fn (Builder $q) => $q->where('expires_at', '<=', now())->orWhere('last_used_at', '<=', $cutoff))->delete();
        ChatAttachment::query()->whereNull('message_id')->where('created_at', '<=', now()->subDay())->orderBy('id')->chunkById(100, function ($rows) use (&$counts): void {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$counts): void {
                    $locked = ChatAttachment::query()->whereKey($row->id)->whereNull('message_id')->lockForUpdate()->first();
                    if ($locked === null) { return; }
                    if (!$this->files->delete($locked->storage_path, \App\Models\Organization::query()->findOrFail($locked->organization_id))) {
                        throw new RuntimeException('assistant_retention_file_delete_failed');
                    }
                    $locked->delete();
                    $counts['attachments']++;
                    $counts['files']++;
                });
            }
        });

        return ['mode' => 'execute', 'days' => $days, 'cutoff' => $cutoff->toISOString()] + $counts;
    }

    public function deleteConversation(Conversation $conversation, ?\DateTimeInterface $cutoff = null, ?array &$counts = null): bool
    {
        $deleted = DB::transaction(function () use ($conversation, $cutoff): ?array {
            $locked = Conversation::query()->lockForUpdate()->find($conversation->id);
            $activity = $locked?->last_activity_at ?? $locked?->created_at;
            if (! $locked || ($cutoff && (! $activity || $activity->gt($cutoff)))) {
                return null;
            }
            $result = $this->emptyCounts();
            foreach (ChatAttachment::query()->where('organization_id', $locked->organization_id)->where('conversation_id', $locked->id)->lockForUpdate()->get() as $attachment) {
                if (!$this->files->delete($attachment->storage_path, $locked->organization)) {
                    throw new RuntimeException('assistant_retention_file_delete_failed');
                }
                $attachment->delete();
                $result['attachments']++;
                $result['files']++;
            }
            foreach ($this->reportCandidates($locked) as $report) {
                if (! $this->files->delete($report->path, $locked->organization)) {
                    throw new RuntimeException('assistant_retention_file_delete_failed');
                }
                $report->delete();
                $result['reports']++;
                $result['files']++;
            }
            foreach ($this->documentCandidates($locked)->cursor() as $document) {
                if (! $this->files->delete($document->storage_path, $locked->organization)) {
                    throw new RuntimeException('assistant_retention_file_delete_failed');
                }
                RagSource::query()->where('organization_id', $locked->organization_id)->where('source_type', 'file_document')
                    ->where('entity_type', 'assistant_document')->where('entity_id', (string) $document->id)->delete();
                $document->delete();
                $result['documents']++;
                $result['files']++;
            }
            $result['messages'] = $locked->messages()->count();
            $result['summaries'] = $locked->summary()->count();
            $result['memories'] = AssistantMemory::query()->where('conversation_id', $locked->id)->count();
            $locked->delete();
            $result['conversations'] = 1;

            return $result;
        });
        $counts = $deleted ?? $this->emptyCounts();

        return $deleted !== null;
    }

    private function expiredConversations(Carbon $cutoff): Builder
    {
        return Conversation::query()->where(fn (Builder $query) => $query->where('last_activity_at', '<=', $cutoff)
            ->orWhere(fn (Builder $empty) => $empty->whereNull('last_activity_at')->where('created_at', '<=', $cutoff)));
    }

    private function documentCandidates(Conversation $conversation): Builder
    {
        return AIAssistantDocument::query()->where('organization_id', $conversation->organization_id)
            ->where('metadata->conversation_id', $conversation->id)->where('metadata->assistant_owned', true)
            ->where('storage_path', 'like', 'org-'.$conversation->organization_id.'/ai-assistant/%');
    }

    private function reportCandidates(Conversation $conversation, ?Carbon $previewCutoff = null): \Generator
    {
        $seen = [];
        foreach ($conversation->messages()->select(['id', 'metadata'])->cursor() as $message) {
            foreach ($this->artifacts(is_array($message->metadata) ? $message->metadata : []) as $artifact) {
                $path = $artifact['storage_path'] ?? null;
                $reportId = $artifact['report_file_id'] ?? null;
                if (! is_string($path) || (! is_string($reportId) && ! is_int($reportId)) || isset($seen[(string) $reportId])
                    || ! str_starts_with($path, 'org-'.$conversation->organization_id.'/personal-files/')) {
                    continue;
                }
                $seen[(string) $reportId] = true;
                $report = ReportFile::query()->whereKey($reportId)->where('organization_id', $conversation->organization_id)->where('type', 'reports')->where('path', $path)->first();
                if (! $report) {
                    continue;
                }
                $others = DB::table('ai_messages')->join('ai_conversations', 'ai_conversations.id', '=', 'ai_messages.conversation_id')
                    ->where('ai_messages.conversation_id', '!=', $conversation->id)
                    ->whereRaw('ai_messages.metadata::text LIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path).'%']);
                if ($previewCutoff) {
                    $others->where(fn ($query) => $query->where('ai_conversations.id', '>', $conversation->id)
                        ->orWhereRaw('COALESCE(ai_conversations.last_activity_at, ai_conversations.created_at) > ?', [$previewCutoff]));
                }
                if (! $others->exists()) {
                    yield $report;
                }
            }
        }
    }

    private function cutoff(int $days): Carbon
    {
        if ($days < 1 || $days > 90) {
            throw new RuntimeException('assistant_retention_days_invalid');
        }

        return now()->subDays($days);
    }

    private function emptyCounts(): array
    {
        return ['conversations' => 0, 'messages' => 0, 'summaries' => 0, 'memories' => 0, 'documents' => 0, 'attachments' => 0, 'reports' => 0, 'files' => 0];
    }

    private function artifacts(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $value) {
            if (! is_array($value)) {
                continue;
            }
            if (isset($value['storage_path'], $value['report_file_id'])) {
                $result[] = $value;
            }
            $result = array_merge($result, $this->artifacts($value));
        }

        return $result;
    }
}
