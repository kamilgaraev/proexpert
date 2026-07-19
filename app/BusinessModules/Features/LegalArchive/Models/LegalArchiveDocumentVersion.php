<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalArchiveDocumentVersion extends Model
{
    private static int $technicalMutationDepth = 0;

    protected $fillable = [
        'document_id',
        'document_file_id',
        'organization_id',
        'version_number',
        'version_label',
        'is_current',
        'status',
        'processing_status',
        'file_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'content_hash',
        'metadata_hash',
        'uploaded_by_user_id',
        'uploaded_at',
        'metadata',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $version): void {
            $dirty = array_keys($version->getDirty());
            $allowed = ['processing_status', 'is_current', 'updated_at'];
            $originalStatus = (string) $version->getOriginal('status');

            if (
                self::$technicalMutationDepth < 1
                || array_diff($dirty, $allowed) !== []
                || in_array($originalStatus, ['signed', 'frozen'], true)
            ) {
                throw new ImmutableDataException(self::class, 'update');
            }

            if ($version->isDirty('processing_status')) {
                $from = (string) $version->getOriginal('processing_status');
                $to = (string) $version->processing_status;
                if ($from !== 'quarantine' || ! in_array($to, ['ready', 'failed'], true)) {
                    throw new ImmutableDataException(self::class, 'transition');
                }
            }
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }

    public static function technicalMutation(Closure $mutation): mixed
    {
        self::$technicalMutationDepth++;

        try {
            return $mutation();
        } finally {
            self::$technicalMutationDepth--;
        }
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function documentFile(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocumentFile::class, 'document_file_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
