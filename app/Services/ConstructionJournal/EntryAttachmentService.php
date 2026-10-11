<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalEntryAttachment;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EntryAttachmentService
{
    public const MAXIMUM_BYTES = 10 * 1024 * 1024;

    private const DOWNLOAD_TTL = 300;

    public function __construct(
        private readonly ConstructionJournalAccessService $access,
        private readonly FileService $files,
    ) {}

    public function canUpload(User $actor, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;

        return ! $entry->isReportingEntry() && ! $entry->trashed() && $journal !== null && ! $journal->trashed()
            && $journal->project !== null
            && (int) $journal->organization_id === (int) $journal->project->organization_id
            && $this->access->canWrite($actor, $journal, ['edit', '*'])
            && Gate::forUser($actor)->allows('update', $entry);
    }

    public function list(User $actor, ConstructionJournalEntry $entry, ?ConstructionJournal $journal = null): array
    {
        $this->assertReadable($actor, $entry, $journal);

        return $entry->attachments()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (JournalEntryAttachment $attachment): array => $this->metadata($attachment))->all();
    }

    public function upload(User $actor, ConstructionJournalEntry $entry, UploadedFile $file, ?ConstructionJournal $journal = null): array
    {
        $this->assertContext($entry, $journal);
        if (! $this->canUpload($actor, $entry)) {
            throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        Validator::make(['file' => $file], ['file' => ['bail', 'required', 'file', 'max:10240', 'image', 'mimetypes:image/png,image/jpeg,image/webp']])->validate();
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('journal_attachment_read_failed');
        }
        try {
            $contents = stream_get_contents($stream, self::MAXIMUM_BYTES + 1);
        } finally {
            fclose($stream);
        }
        $size = $contents === false ? 0 : strlen($contents);
        $mime = $contents === false ? '' : (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if ($size < 1 || $size > self::MAXIMUM_BYTES || ! isset($extensions[$mime ?: ''])) {
            throw ValidationException::withMessages(['file' => trans_message('construction_journal.errors.attachment_invalid')]);
        }
        $id = (string) Str::uuid();
        $key = null;
        $attempted = false;

        try {
            return DB::transaction(function () use ($actor, $entry, $journal, $file, $contents, $size, $mime, $extensions, $id, &$key, &$attempted): array {
                $lockedJournal = ConstructionJournal::query()->whereKey($entry->journal_id)->lockForUpdate()->firstOrFail();
                $lockedEntry = ConstructionJournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                $lockedEntry->setRelation('journal', $lockedJournal);
                $this->assertContext($lockedEntry, $journal);
                if (! $this->canUpload($actor, $lockedEntry)) {
                    throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
                }
                $key = 'org-'.$lockedJournal->organization_id.'/construction-journals/'.$lockedJournal->id.'/entries/'.$lockedEntry->id.'/attachments/'.$id.'.'.$extensions[$mime];
                $attachment = JournalEntryAttachment::query()->create([
                    'id' => $id,
                    'journal_entry_id' => $lockedEntry->id,
                    'storage_key' => $key,
                    'file_name' => $this->safeName($file->getClientOriginalName(), $extensions[$mime]),
                    'mime_type' => $mime,
                    'size' => $size,
                    'sha256' => hash('sha256', $contents),
                    'uploaded_by' => ['id' => $actor->id, 'name' => $actor->name, 'organization_id' => (int) $actor->current_organization_id],
                    'created_at' => now(),
                ]);
                $attempted = true;
                $stored = $this->files->putPrivate($key, $contents, $mime, $attachment->sha256);
                if ($stored->key !== $key || $stored->sizeBytes !== $size || $stored->sha256 !== $attachment->sha256 || $stored->mime !== $mime) {
                    throw new \RuntimeException('journal_attachment_identity_invalid');
                }

                return $this->metadata($attachment);
            });
        } catch (Throwable $exception) {
            if ($attempted && $key !== null) {
                try {
                    $this->files->deleteCurrent($key);
                } catch (Throwable $cleanupException) {
                    Log::error('construction_journal.attachment_cleanup_failed', ['entry_id' => $entry->id, 'attachment_id' => $id, 'error_class' => $cleanupException::class]);
                }
            }

            throw $exception;
        }
    }

    public function download(User $actor, ConstructionJournalEntry $entry, JournalEntryAttachment $attachment, ?ConstructionJournal $journal = null): array
    {
        $this->assertReadable($actor, $entry, $journal);
        $prefix = 'org-'.$entry->journal->organization_id.'/construction-journals/'.$entry->journal_id.'/entries/'.$entry->id.'/attachments/';
        if ((int) $attachment->journal_entry_id !== (int) $entry->id || ! str_starts_with($attachment->storage_key, $prefix)) {
            throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }

        return ['url' => $this->files->temporaryDownloadUrl($attachment->storage_key, self::DOWNLOAD_TTL), 'expires_at' => now()->addSeconds(self::DOWNLOAD_TTL)->toIso8601String()];
    }

    public function metadata(JournalEntryAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'created_at' => $attachment->created_at->toIso8601String(),
            'uploaded_by' => $attachment->uploaded_by,
        ];
    }

    private function assertReadable(User $actor, ConstructionJournalEntry $entry, ?ConstructionJournal $journal): void
    {
        $this->assertContext($entry, $journal);
        $this->access->assertReadable($actor, $entry->journal);
    }

    private function assertContext(ConstructionJournalEntry $entry, ?ConstructionJournal $journal): void
    {
        $actualJournal = $entry->journal;
        if ($entry->trashed() || ! $actualJournal || $actualJournal->trashed()
            || (int) $entry->journal_id !== (int) $actualJournal->id
            || ($journal !== null && (int) $entry->journal_id !== (int) $journal->id)
            || ! $actualJournal->project
            || (int) $actualJournal->organization_id !== (int) $actualJournal->project->organization_id) {
            throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
    }

    private function safeName(string $name, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N} ._-]/u', '_', $base), '. ');

        return mb_strcut($base !== '' ? $base : 'photo', 0, 230, 'UTF-8').'.'.$extension;
    }
}
