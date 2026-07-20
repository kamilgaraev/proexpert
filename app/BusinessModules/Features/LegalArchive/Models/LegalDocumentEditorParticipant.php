<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use Illuminate\Database\Eloquent\Model;

final class LegalDocumentEditorParticipant extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id', 'editor_session_id', 'actor_key', 'user_id',
        'provider_user_id', 'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new ImmutableDataException(self::class, 'update');
        });
        self::deleting(static function (): never {
            throw new ImmutableDataException(self::class, 'delete');
        });
    }
}
